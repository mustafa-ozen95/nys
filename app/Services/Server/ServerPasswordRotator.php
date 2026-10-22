<?php

declare(strict_types=1);

namespace App\Services\Server;

use App\Enums\ActivityChannel;
use App\Enums\PasswordRotationStage;
use App\Enums\PasswordRotationStatus;
use App\Models\Server;
use App\Models\ServerPasswordRotation;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Server\Driver\ServerDriver;
use App\Support\Credentials\PasswordGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bir sunucu hesabına yeni ve rastgele bir parola verir.
 *
 * SIRA DEĞİŞMEZ, çünkü güvenliği veren şey sıranın kendisidir:
 *
 *  1. Makinede tam yetkili bir KURTARMA hesabı açılır.
 *  2. O hesapla gerçekten bağlanılabildiği KANITLANIR. Kanıtlanamazsa hesap
 *     silinir ve tur burada biter — çalışmayan bir kurtarma hesabı güvence
 *     değil, açıkta duran fazladan bir yetkidir.
 *  3. Asıl hesabın parolası KURTARMA HESABIYLA değiştirilir. Değiştirdiğin
 *     parolayla bağlı olduğun oturum, iş yarıda kalırsa seni dışarıda bırakır.
 *  4. Yeni parola DENENİR: yeni bir oturum açılır.
 *  5. Ancak bundan sonra paneldeki kayıt güncellenir.
 *  6. Kurtarma hesabı YENİ parolayla silinir; silme işleminin kendisi yeni
 *     parolanın çalıştığının ikinci kanıtıdır.
 *
 * Herhangi bir adımda takılırsa kurtarma hesabı makinede BIRAKILIR ve
 * parolası panelde şifreli durur: makineye dönebileceğimiz bir kapı hep açık
 * kalır. Ekran bunu uyarı olarak gösterir, `onar()` kapatır.
 *
 * KURTARMA HESABI AÇILAMAYAN PLATFORMDA (Windows, varsayılan ayar) sıra
 * kısalır: parola kendi oturumundan değiştirilir, doğrulanır ve tutmazsa eski
 * parola aynı yoldan geri konur. Geri dönüş garantisi zayıftır ve bu bilinçli
 * bir taviz değil, platformun dayattığı sınırdır (bkz. WindowsDriver).
 */
class ServerPasswordRotator
{
    /** Kayıt başına döndürme kilidi. */
    private const LOCK = 'nys:sunucu-dondurme:';

    public function __construct(
        private DriverSelector $selector,
        private PasswordGenerator $generator,
        private ActivityRecorder $activity,
    ) {}

    public function rotate(Server $server, ?User $user = null, ?string $reason = null): ServerPasswordRotation
    {
        $round = $server->passwordRotations()->create([
            'user_id' => $user?->id,
            'status' => PasswordRotationStatus::Running,
            'stage' => PasswordRotationStage::Started,
            'started_at' => now(),
        ]);

        $engel = $this->selector->engel($server);

        if ($engel !== null) {
            return $this->finish($server, $round, PasswordRotationStatus::Failed, $engel, $user, $reason);
        }

        /*
         * AYNI SUNUCUYA İKİ TUR AYNI ANDA GİREMEZ.
         *
         * Tetikleyen iki yol var: tek sunucu düğmesi ve toplu güncelleme.
         * İkisi aynı anda çalışsaydı ikisi de "önceki parola" olarak
         * kayıttaki değeri okur, ikisi de makinede parolayı değiştirir ve
         * kayda SON YAZAN kalırdı — makinedeki parola ile paneldeki farklı
         * olabilirdi. Üstelik fark ancak bir sonraki girişte anlaşılırdı.
         *
         * Kilit SUNUCU BAŞINADIR: farklı sunucular birbirini beklemez.
         */
        $lock = Cache::lock(self::LOCK.$server->getKey(), $this->lockSecond());

        if (! $lock->get()) {
            return $this->finish(
                $server,
                $round,
                PasswordRotationStatus::Failed,
                'Bu sunucu için bir güncelleme zaten sürüyor; ikinci tur başlatılmadı.',
                $user,
                $reason,
            );
        }

        try {
            $this->run($server, $round, $user, $reason);
        } catch (Throwable $e) {
            return $this->dropped($server, $round, $e, $user, $reason);
        } finally {
            /* Tur nasıl biterse bitsin kilit BIRAKILIR. */
            $lock->release();
        }

        return $round->refresh();
    }

    /**
     * Yarıda kalmış bir turu kapatır.
     *
     * Hangi kimliğin geçerli olduğu BİLİNMEZ — tur nerede takıldığına göre
     * eski parola, yeni parola ya da yalnızca kurtarma hesabı çalışıyor
     * olabilir. Üçü de sırayla denenir; ilk açılan oturum yolu belirler.
     */
    public function repair(ServerPasswordRotation $round, ?User $user = null): ServerPasswordRotation
    {
        $server = $round->server;

        if (! $round->recovery_active || $server === null) {
            return $round;
        }

        /*
         * Onarım da AYNI KİLİDİ alır. Onarım da makineye bağlanır, kimlik
         * dener ve kurtarma hesabını siler; sürmekte olan bir turun ortasında
         * bunu yapmak, o turun az önce açtığı hesabı silmek demekti.
         */
        $lock = Cache::lock(self::LOCK.$server->getKey(), $this->lockSecond());

        if (! $lock->get()) {
            $this->write($server, $user, null, 'server_password_repair_deferred',
                "{$server->short_name}: bir güncelleme sürüyor, onarım şimdi yapılmadı.");

            return $round;
        }

        try {
            return $this->repairRun($server, $round, $user);
        } finally {
            $lock->release();
        }
    }

    private function run(
        Server $server,
        ServerPasswordRotation $round,
        ?User $user,
        ?string $reason,
    ): void {
        $driver = $this->selector->for($server);

        $driver->recoverySupports()
            ? $this->recoverableOrder($server, $round, $driver, $user, $reason)
            : $this->directOrder($server, $round, $driver, $user, $reason);
    }

    /**
     * Kurtarma hesaplı sıra — parolasını değiştirdiğimiz hesapla bağlandığımız
     * platformlar için (Linux/SSH).
     */
    private function recoverableOrder(
        Server $server,
        ServerPasswordRotation $round,
        ServerDriver $driver,
        ?User $user,
        ?string $reason,
    ): void {
        $recoveryName = $this->recoveryName();
        $recoveryPassword = $this->newPassword();

        /*
         * Kurtarma KİMLİĞİ makineye gitmeden önce kayda geçer: hesap açılırken
         * süreç ölürse makinede parolasını bilmediğimiz bir hesap kalırdı.
         */
        $round->forceFill([
            'recovery_username' => $recoveryName,
            'recovery_password' => $recoveryPassword,
        ])->save();

        $name = (string) $server->connection_username;
        $password = (string) $server->connection_password;

        // 1 — kurtarma hesabı.
        $session = $driver->open($server, $name, $password);

        try {
            /*
             * "MAKİNEDE HESAP DURUYOR" BAYRAĞI ANCAK BURADA KALKAR: oturum hiç
             * açılamadıysa makinede bir şey yoktur ve yanlış alarm verilmez.
             * Bayrak yine de hesabın açılmasından ÖNCE kalkar — açılış yarıda
             * kesilirse hesap makinede kalmış olabilir ve o ihtimalde
             * susmaktansa fazladan uyarmak doğrudur.
             */
            $round->forceFill(['recovery_active' => true])->save();

            $driver->recoveryOpen($session, $recoveryName, $recoveryPassword);
            $round->forceFill(['stage' => PasswordRotationStage::RecoveryCreated])->save();
        } finally {
            $session->close();
        }

        // 2 — kurtarma hesabı gerçekten çalışıyor mu.
        if (! $driver->verify($server, $recoveryName, $recoveryPassword)) {
            $this->recoveryDelete($server, $round, $driver, $name, $password);

            throw new RotationError('Kurtarma hesabı açıldı ama onunla bağlanılamadı; tur iptal edildi.');
        }

        $round->forceFill(['stage' => PasswordRotationStage::RecoveryVerified])->save();

        // 3 — parola değişimi, KURTARMA hesabıyla.
        $new = $this->newPassword();
        $round->forceFill(['new_password' => $new])->save();

        $recovery = $driver->open($server, $recoveryName, $recoveryPassword);

        try {
            $driver->passwordChange($recovery, $name, $new);
            $round->forceFill(['stage' => PasswordRotationStage::PasswordChanged])->save();
        } finally {
            $recovery->close();
        }

        // 4 — yeni parola deneniyor.
        if (! $driver->verify($server, $name, $new)) {
            throw new RotationError('Yeni parola makineye yazıldı ama onunla bağlanılamadı.');
        }

        $round->forceFill(['stage' => PasswordRotationStage::PasswordVerified])->save();

        // 5 — paneldeki kayıt ancak şimdi değişir.
        $this->passwordWrite($server, $new);

        // 6 — kurtarma hesabı YENİ parolayla silinir.
        $this->recoveryDelete($server, $round, $driver, $name, $new);

        $round->forceFill(['stage' => PasswordRotationStage::RecoveryRemoved])->save();

        $this->finish(
            $server, $round, PasswordRotationStatus::Succeeded,
            'Parola güncellendi, doğrulandı ve kurtarma hesabı silindi.',
            $user, $reason,
        );
    }

    /**
     * Kurtarma hesabı AÇILAMAYAN platformda sıra.
     *
     * Parola kendi oturumundan değiştirilir. Tutmazsa eski parola aynı yoldan
     * geri konur — ama makine o sırada zaten yeni parolayı kabul ediyorsa geri
     * dönüş de çalışır; çalışmazsa elde kalan tek şey kayıttaki parolalardır
     * ve ikisi de tur kaydında durur.
     */
    private function directOrder(
        Server $server,
        ServerPasswordRotation $round,
        ServerDriver $driver,
        ?User $user,
        ?string $reason,
    ): void {
        $name = (string) $server->connection_username;
        $previous = (string) $server->connection_password;
        $new = $this->newPassword();

        $round->forceFill(['new_password' => $new])->save();

        $session = $driver->open($server, $name, $previous);

        try {
            $driver->passwordChange($session, $name, $new);
            $round->forceFill(['stage' => PasswordRotationStage::PasswordChanged])->save();
        } finally {
            $session->close();
        }

        if ($driver->verify($server, $name, $new)) {
            $round->forceFill(['stage' => PasswordRotationStage::PasswordVerified])->save();
            $this->passwordWrite($server, $new);

            $this->finish(
                $server, $round, PasswordRotationStatus::Succeeded,
                'Parola güncellendi ve doğrulandı.',
                $user, $reason,
            );

            return;
        }

        /* Doğrulanamadı: aynı kanaldan eskiye dön. */
        if ($previous !== '') {
            $back = $driver->open($server, $name, $new);

            try {
                $driver->passwordChange($back, $name, $previous);
            } finally {
                $back->close();
            }

            if ($driver->verify($server, $name, $previous)) {
                $this->finish(
                    $server, $round, PasswordRotationStatus::Failed,
                    'Yeni parola doğrulanamadı; eski parola geri kondu ve çalışıyor.',
                    $user, $reason,
                );

                return;
            }
        }

        throw new RotationError('Yeni parola doğrulanamadı ve eskisi geri konamadı.');
    }

    private function repairRun(
        Server $server,
        ServerPasswordRotation $round,
        ?User $user,
    ): ServerPasswordRotation {
        $driver = $this->selector->for($server);
        $name = (string) $server->connection_username;

        /*
         * Kayıttaki parola ÖNCE denenir. Tur 3. adımdan sonra takıldıysa
         * makinedeki parola yeni olabilir; o yüzden denenen parola da listede.
         * Sonuncu çare kurtarma hesabının kendisi.
         */
        $candidates = [
            [$name, (string) $server->connection_password],
            [$name, (string) $round->new_password],
            [(string) $round->recovery_username, (string) $round->recovery_password],
        ];

        foreach ($candidates as [$who, $password]) {
            if ($who === '' || $password === '') {
                continue;
            }

            if (! $driver->verify($server, $who, $password)) {
                continue;
            }

            /*
             * Makinedeki parola denenen parolaysa panel geride kalmış demektir;
             * ikisi ayrışmış hâlde bırakılmaz.
             */
            if ($who === $name && $password === (string) $round->new_password) {
                $this->passwordWrite($server, $password);
            }

            try {
                $this->recoveryDelete($server, $round, $driver, $who, $password);
            } catch (Throwable $e) {
                return $this->finish(
                    $server, $round, PasswordRotationStatus::Failed,
                    'Kurtarma hesabı silinemedi: '.$e->getMessage(),
                    $user, null,
                );
            }

            $round->forceFill(['stage' => PasswordRotationStage::RecoveryRemoved])->save();

            return $this->finish(
                $server, $round, PasswordRotationStatus::Succeeded,
                'Tur kapatıldı; kurtarma hesabı silindi.',
                $user, null,
            );
        }

        return $this->finish(
            $server, $round, PasswordRotationStatus::Failed,
            'Hiçbir kimlikle bağlanılamadı. Makineye elle girip kurtarma hesabını silmek gerekiyor.',
            $user, null,
        );
    }

    /** Kurtarma hesabını siler ve panelden sırrını temizler. */
    private function recoveryDelete(
        Server $server,
        ServerPasswordRotation $round,
        ServerDriver $driver,
        string $userName,
        string $password,
    ): void {
        if (blank($round->recovery_username)) {
            return;
        }

        $session = $driver->open($server, $userName, $password);

        try {
            $driver->recoveryDelete($session, (string) $round->recovery_username);
        } finally {
            $session->close();
        }

        $round->recoveryClean();
    }

    /**
     * Paneldeki kaydı yeni parolayla günceller.
     *
     * Sunucunun TEK kimliği var (connection_username/password); ayrı bir
     * döndürme hesabı yok, bu yüzden yayılacak başka bir alan da yok.
     */
    private function passwordWrite(Server $server, string $newPassword): void
    {
        $server->forceFill(['connection_password' => $newPassword])->save();
    }

    private function dropped(
        Server $server,
        ServerPasswordRotation $round,
        Throwable $e,
        ?User $user,
        ?string $reason,
    ): ServerPasswordRotation {
        return $this->finish($server, $round, PasswordRotationStatus::Failed, $e->getMessage(), $user, $reason);
    }

    private function finish(
        Server $server,
        ServerPasswordRotation $round,
        PasswordRotationStatus $status,
        string $message,
        ?User $user,
        ?string $reason,
    ): ServerPasswordRotation {
        $round->forceFill([
            'status' => $status,
            'error' => $status === PasswordRotationStatus::Succeeded ? null : Str::limit($message, 500),
            'finished_at' => now(),
        ])->save();

        $this->write(
            $server,
            $user,
            $reason,
            $status === PasswordRotationStatus::Succeeded ? 'server_password_rotated' : 'server_password_rotation_failed',
            "{$server->short_name}: {$message}",
        );

        return $round->refresh();
    }

    private function write(
        Server $server,
        ?User $user,
        ?string $reason,
        string $action,
        string $text,
    ): void {
        $this->activity->recordAs(
            $user?->id,
            $reason,
            request()->ip(),
            $server,
            $action,
            $text,
            ActivityChannel::Crud,
        );
    }

    private function newPassword(): string
    {
        return $this->generator->generate((int) config('server.password_length', 24));
    }

    /**
     * Kurtarma hesabının adı her turda FARKLIDIR.
     *
     * Sabit bir ad, yarıda kalmış eski bir turun hesabıyla çakışır ve
     * "useradd: user already exists" hatası turu daha ilk adımda düşürürdü.
     */
    private function recoveryName(): string
    {
        return 'nys-kurtarma-'.Str::lower(Str::random(6));
    }

    private function lockSecond(): int
    {
        return (int) config('server.lock_seconds', 600);
    }
}
