<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Enums\ActivityChannel;
use App\Enums\JunosUserClass;
use App\Enums\PasswordRotationStage;
use App\Enums\PasswordRotationStatus;
use App\Models\NetworkSwitch;
use App\Models\SwitchPasswordRotation;
use App\Models\User;
use App\Services\Activity\ActivityChain;
use App\Services\Netconf\ConfigurationWriter;
use App\Services\Netconf\DeviceSettingsSynchronizer;
use App\Services\Netconf\NetconfClient;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\NetconfTarget;
use App\Support\Credentials\PasswordGenerator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bir switchin panel hesabına yeni ve rastgele bir parola verir.
 *
 * SIRA GÜVENLİĞİN KENDİSİDİR:
 *
 *  1. Cihazda tam yetkili bir KURTARMA hesabı açılır.
 *  2. O hesapla gerçekten bağlanılabildiği KANITLANIR. Kanıtlanamazsa hesap
 *     silinir ve tur burada biter — çalışmayan bir kurtarma hesabı güvence
 *     değil, açıkta duran fazladan bir yetkidir.
 *  3. Panel hesabının parolası KURTARMA HESABIYLA değiştirilir. Kendi
 *     parolanı değiştirdiğin oturumla onay veremezsin: geri alınabilir
 *     commit'in onayı YENİ bir oturumda verilir ve o oturum artık eski
 *     parolayı kabul etmez. Kurtarma hesabının parolası değişmediği için
 *     hem commit hem onay sorunsuz atılır.
 *  4. Yeni parola DENENİR: yeni bir oturum açılır.
 *  5. Ancak bundan sonra panelin kimlik kaydı güncellenir.
 *  6. Kurtarma hesabı YENİ parolayla silinir; silme işleminin kendisi yeni
 *     parolanın çalıştığının ikinci kanıtıdır.
 *
 * Herhangi bir adımda takılırsa kurtarma hesabı cihazda BIRAKILIR ve
 * parolası panelde şifreli durur: cihaza dönebileceğimiz bir kapı hep açık
 * kalır. Ekran bunu uyarı olarak gösterir, `repair()` kapatır.
 *
 * Yeni parola commit'ten ÖNCE kayda yazılır. Yazma ile doğrulama arasında
 * süreç ölürse parola yalnızca bellekte kalmış olurdu ve cihaz kendi
 * parolasını bilmediğimiz bir cihaza dönerdi.
 */
class SwitchPasswordRotator
{
    public function __construct(
        private NetconfClient $client,
        private ConfigurationWriter $writer,
        private PasswordGenerator $passwords,
        private DeviceSettingsSynchronizer $settings,
        private ActivityChain $activity,
    ) {}

    /**
     * @param  ?string  $password  Elle belirlenen parola. Boş bırakılırsa
     *                             rastgele üretilir. Kullanıcının verdiği
     *                             parola aynı güvenli sıradan geçer — elle
     *                             seçilmiş olması onu daha az tehlikeli
     *                             yapmaz, yanlış giderse switch yine
     *                             yönetilemez hâle gelir.
     *
     * @throws NetconfException
     */
    public function rotate(NetworkSwitch $switch, ?User $user = null, ?string $password = null): SwitchPasswordRotation
    {
        $rotation = $switch->passwordRotations()->create([
            'user_id' => $user?->id,
            'status' => PasswordRotationStatus::Running,
            'stage' => PasswordRotationStage::Started,
            'started_at' => now(),
        ]);

        try {
            /*
             * Engel kontrolü kaydın İÇİNDE yapılır: kuyruktan çalışan bir tur
             * sessizce ölmemeli, neden başlamadığı ekranda görünmeli.
             */
            $this->guard($switch);
            $this->run($switch, $rotation, $password);
        } catch (Throwable $exception) {
            return $this->fail($switch, $rotation, $exception);
        }

        return $rotation;
    }

    /**
     * Yarıda kalmış bir turu kapatır: cihazda duran kurtarma hesabını siler,
     * gerekiyorsa panel hesabının parolasını yeniden kurar.
     *
     * Hangi kimliğin geçerli olduğu BİLİNMEZ — tur nerede takıldığına göre
     * eski parola, yeni parola ya da yalnızca kurtarma hesabı çalışıyor
     * olabilir. Üçü de sırayla denenir; ilk açılan oturum yolu belirler.
     *
     * @throws NetconfException
     */
    public function repair(SwitchPasswordRotation $rotation, ?User $user = null): SwitchPasswordRotation
    {
        $switch = $rotation->switch;

        if (! $rotation->recovery_active || $switch === null) {
            return $rotation;
        }

        $username = $this->username($switch);

        // Panel hesabı çalışıyorsa yapılacak tek iş kurtarma hesabını silmek.
        foreach ([$this->storedPassword($switch), $rotation->new_password] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $target = $this->target($switch, $username, $candidate);

            if (! $this->reachable($target)) {
                continue;
            }

            $this->storePassword($switch, $username, $candidate);
            $this->removeRecovery($switch, $rotation, $target);

            return $this->close($switch, $rotation, $user, 'kurtarma hesabi temizlendi');
        }

        /*
         * Panel hesabına hiçbir parolayla girilemiyor: cihaza tek kapı
         * kurtarma hesabı. Panel hesabına yeni bir parola kurulur ve tur
         * normal akışın kalan yarısı gibi tamamlanır.
         */
        $recovery = $this->recoveryTarget($switch, $rotation);

        if ($recovery === null || ! $this->reachable($recovery)) {
            throw new NetconfException(
                "{$switch->hostname}: ne panel hesabına ne kurtarma hesabına bağlanılabiliyor. Cihaza konsoldan girilmeli."
            );
        }

        $this->changePassword($switch, $rotation, $recovery, $username);
        $target = $this->target($switch, $username, (string) $rotation->new_password);
        $this->verify($target, $switch);
        $this->storePassword($switch, $username, (string) $rotation->new_password);
        $this->removeRecovery($switch, $rotation, $target);

        return $this->close($switch, $rotation, $user, 'parola kurtarma hesabiyla yeniden kuruldu');
    }

    /**
     * Turun kendisi. Her adım kayda geçer: yarıda kalırsa cihazın hangi
     * durumda olduğunu yalnızca bu söyler.
     */
    private function run(NetworkSwitch $switch, SwitchPasswordRotation $rotation, ?string $password = null): void
    {
        $username = $this->username($switch);
        $panel = $this->target($switch, $username, $this->storedPassword($switch) ?? '');

        // 1 — kurtarma hesabı.
        $recoveryName = (string) config('credentials.rotation.recovery_username');
        $recoveryPassword = $this->passwords->generate();

        $rotation->forceFill([
            'recovery_username' => $recoveryName,
            'recovery_password' => $recoveryPassword,
            'recovery_active' => true,
        ])->save();

        /*
         * GERİ ALINABİLİR COMMIT GEREKMEZ.
         *
         * Bu yazma yalnızca YENİ bir hesap ekliyor; şu an kullandığımız panel
         * hesabına dokunmuyor, yani kendimizi dışarıda bırakamaz. Geri
         * alınabilir commit ise cihaza bir commit ve bir oturum daha mal
         * oluyor (ölçüm: EX4200'de commit ~7,5 sn, oturum açılışı
         * ~2,2 sn; commit dışındaki bütün RPC'ler toplam 3 saniye).
         *
         * Yazma yarıda kalırsa cihazda fazladan bir kurtarma hesabı kalır —
         * onu zaten `repair()` temizliyor ve bu risk geri alınabilir commit'te
         * de aynen vardı (onay hemen ardından veriliyordu).
         */
        $this->writer->applyOn(
            $panel,
            $this->userElement($recoveryName, JunosUserClass::SuperUser->value, $recoveryPassword),
            'kurtarma hesabi olusturuldu',
            geriAlinabilir: false,
        );

        $rotation->forceFill(['stage' => PasswordRotationStage::RecoveryCreated])->save();

        // 2 — kurtarma hesabı gerçekten çalışıyor mu.
        $recovery = $this->target($switch, $recoveryName, $recoveryPassword);

        try {
            $this->verify($recovery, $switch);
        } catch (Throwable $exception) {
            /*
             * Çalışmayan kurtarma hesabı güvence değil, açıkta duran
             * fazladan bir yetkidir. Panel hesabı hâlâ geçerli olduğu için
             * temizlenebilir; temizlenir ve tur burada biter.
             */
            $this->removeRecovery($switch, $rotation, $panel);

            throw $exception;
        }

        $rotation->forceFill(['stage' => PasswordRotationStage::RecoveryVerified])->save();

        // 3 — parola değişimi, kurtarma hesabıyla.
        $this->changePassword($switch, $rotation, $recovery, $username, $password);

        // 4 — yeni parola deneniyor.
        $target = $this->target($switch, $username, (string) $rotation->new_password);
        $this->verify($target, $switch);
        $rotation->forceFill(['stage' => PasswordRotationStage::PasswordVerified])->save();

        // 5 — panelin kimlik kaydı ancak şimdi değişir.
        $this->storePassword($switch, $username, (string) $rotation->new_password);

        // 6 — kurtarma hesabı yeni parolayla silinir.
        $this->removeRecovery($switch, $rotation, $target);

        $rotation->forceFill([
            'status' => PasswordRotationStatus::Succeeded,
            'stage' => PasswordRotationStage::RecoveryRemoved,
            'finished_at' => now(),
        ])->save();

        $this->note($switch, 'parola degistirildi', $rotation->user_id);
    }

    /**
     * Panel hesabının parolasını KURTARMA oturumundan değiştirir.
     *
     * Yeni parola commit'ten önce kayda yazılır; arada süreç ölürse cihazda
     * bilmediğimiz bir parola kalmasın.
     */
    private function changePassword(
        NetworkSwitch $switch,
        SwitchPasswordRotation $rotation,
        NetconfTarget $recovery,
        string $username,
        ?string $password = null,
    ): void {
        $password ??= $this->passwords->generate();

        $rotation->forceFill(['new_password' => $password])->save();

        /*
         * BURADA geri alınabilir commit ŞART: kendi giriş parolamızı
         * değiştiriyoruz. Onay ikinci bir oturumda veriliyor ve o oturum
         * açılamazsa cihaz kendini eski parolaya döndürüyor. Turun bütün
         * güvenliği bu adımda.
         */
        $this->writer->applyOn(
            $recovery,
            $this->userElement($username, null, $password),
            'panel hesabi parolasi degistirildi',
        );

        $rotation->forceFill(['stage' => PasswordRotationStage::PasswordChanged])->save();
    }

    /**
     * Kurtarma hesabını cihazdan siler. Silme, verilen kimlikle atılır;
     * silinecek hesabın KENDİ oturumu kullanılamaz — onay oturumu artık
     * açılamazdı ve cihaz commit'i geri alırdı.
     */
    private function removeRecovery(
        NetworkSwitch $switch,
        SwitchPasswordRotation $rotation,
        NetconfTarget $target,
    ): void {
        $name = (string) ($rotation->recovery_username ?? config('credentials.rotation.recovery_username'));

        /*
         * Geri alınabilir commit gerekmez: silinen hesap, ŞU AN kullandığımız
         * hesap değil (verilen kimlik panel hesabıdır ve parolası bir adım
         * önce doğrulanmıştır). Başka birinin hesabını silmek bizi dışarıda
         * bırakamaz. Kapanış okuması da aynı oturumda yapılır.
         */
        $this->writer->applyOn(
            $target,
            '<configuration><system><login><user delete="delete"><name>'
            .htmlspecialchars($name, ENT_XML1 | ENT_QUOTES)
            .'</name></user></login></system></configuration>',
            'kurtarma hesabi silindi',
            fn (NetconfSession $session) => $this->settings->sync($switch, $session),
            geriAlinabilir: false,
        );

        $rotation->clearRecovery();
    }

    /**
     * Panelin kimlik kaydı. AYNI kullanıcı adını taşıyan bütün kayıtlar
     * güncellenir: cihazdaki hesap tektir, SSH ile NETCONF satırları onun iki
     * yüzüdür. Biri güncellenip diğeri kalırsa uzak oturum açılamaz olur.
     */
    private function storePassword(NetworkSwitch $switch, string $username, string $password): void
    {
        DB::transaction(function () use ($switch, $username, $password): void {
            foreach ($switch->credentials()->get() as $credential) {
                if ($credential->username !== null && strcasecmp($credential->username, $username) === 0) {
                    $credential->forceFill(['password' => $password])->save();
                }
            }
        });

        $switch->load('credentials');
    }

    /**
     * Verilen kimlikle oturum açılabiliyor mu. Cihazın kendi cevabı dışında
     * bir kanıt kabul edilmez.
     *
     * @throws NetconfException
     */
    private function verify(NetconfTarget $target, NetworkSwitch $switch): void
    {
        $this->client->runOn($target, fn (NetconfSession $session) => $session->rpc('<get-software-information/>'));
    }

    private function reachable(NetconfTarget $target): bool
    {
        try {
            $this->client->runOn($target, fn (NetconfSession $session) => $session->rpc('<get-software-information/>'));
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Sınıf null bırakılırsa yazılmaz: var olan bir hesabın parolası
     * değişirken sınıfına dokunulmamalı (cihaz zaten sınıfsız hesabı
     * "Missing mandatory statement: 'class'" ile reddediyor, ama mevcut
     * hesapta sınıf zaten var).
     */
    private function userElement(string $name, ?string $class, string $password): string
    {
        $body = '<name>'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES).'</name>';

        if ($class !== null) {
            $body .= '<class>'.htmlspecialchars($class, ENT_XML1 | ENT_QUOTES).'</class>';
        }

        $body .= '<authentication><plain-text-password-value>'
            .htmlspecialchars($password, ENT_XML1 | ENT_QUOTES)
            .'</plain-text-password-value></authentication>';

        return "<configuration><system><login><user>{$body}</user></login></system></configuration>";
    }

    private function target(NetworkSwitch $switch, string $username, string $password): NetconfTarget
    {
        return new NetconfTarget(
            host: (string) $switch->management_ip,
            port: $switch->netconfCredential?->port ?? (int) config('netconf.port'),
            username: $username,
            password: $password,
            label: (string) $switch->hostname,
            switchId: $switch->id,
        );
    }

    private function recoveryTarget(NetworkSwitch $switch, SwitchPasswordRotation $rotation): ?NetconfTarget
    {
        return $rotation->recovery_username === null || $rotation->recovery_password === null
            ? null
            : $this->target($switch, $rotation->recovery_username, $rotation->recovery_password);
    }

    private function username(NetworkSwitch $switch): string
    {
        $credential = $switch->netconfCredential ?? $switch->sshCredential;

        return (string) $credential?->username;
    }

    private function storedPassword(NetworkSwitch $switch): ?string
    {
        $credential = $switch->netconfCredential ?? $switch->sshCredential;

        return $credential?->password;
    }

    /**
     * Tura başlamadan önce durulacak yerler.
     *
     * @throws NetconfException
     */
    private function guard(NetworkSwitch $switch): void
    {
        $credential = $switch->netconfCredential ?? $switch->sshCredential;

        if ($credential?->username === null || $credential->password === null) {
            throw new NetconfException("{$switch->hostname} için cihaz kimliği tanımlı değil.");
        }

        $recovery = (string) config('credentials.rotation.recovery_username');

        if (strcasecmp($recovery, $credential->username) === 0) {
            throw new NetconfException(
                'Kurtarma hesabının adı panelin bağlandığı hesapla aynı olamaz; kurtarma hesabı asıl hesabın üstüne yazardı.'
            );
        }

        /*
         * Cihazda temizlenmemiş bir kurtarma hesabı varsa yeni tur onun
         * parolasını ezerdi ve cihaza dönüş kapısı kaybolurdu.
         */
        if ($switch->passwordRotations()->stranded()->exists()) {
            throw new NetconfException(
                "{$switch->hostname} cihazında temizlenmemiş bir kurtarma hesabı var; önce o kapatılmalı."
            );
        }
    }

    private function fail(
        NetworkSwitch $switch,
        SwitchPasswordRotation $rotation,
        Throwable $exception,
    ): SwitchPasswordRotation {
        $rotation->forceFill([
            'status' => PasswordRotationStatus::Failed,
            'error' => mb_substr(trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? ''), 0, 1000),
            'finished_at' => now(),
        ])->save();

        $this->note($switch, 'parola degistirilemedi: '.$rotation->error, $rotation->user_id);

        return $rotation;
    }

    private function close(
        NetworkSwitch $switch,
        SwitchPasswordRotation $rotation,
        ?User $user,
        string $summary,
    ): SwitchPasswordRotation {
        /*
         * Onarım bitince tur ARTIK BAŞARILIDIR: cihazla panel aynı parolayı
         * biliyor ve kurtarma hesabı kapandı. Eski hata metni korunur ama
         * "onarıldı" diye işaretlenir — ekranda kalıcı bir kırmızı satır
         * bırakmak, gerçekten bakım isteyen cihazı görünmez kılardı.
         */
        $rotation->forceFill([
            'status' => PasswordRotationStatus::Succeeded,
            'stage' => PasswordRotationStage::RecoveryRemoved,
            'error' => $rotation->error === null ? null : 'onarıldı · '.$rotation->error,
            'finished_at' => now(),
            'user_id' => $user?->id ?? $rotation->user_id,
        ])->save();

        $this->note($switch, $summary, $rotation->user_id);

        return $rotation;
    }

    /**
     * Etkinlik kaydı. PAROLA HİÇBİR ZAMAN YAZILMAZ — ne eskisi ne yenisi ne
     * kurtarma hesabınınki.
     */
    private function note(NetworkSwitch $switch, string $summary, ?int $userId = null): void
    {
        $this->activity->append([
            'user_id' => $userId,
            'subject_type' => $switch->getMorphClass(),
            'subject_id' => $switch->getKey(),
            'channel' => ActivityChannel::Config,
            'action' => 'configured',
            'description' => "{$switch->hostname} · {$summary}",
        ]);
    }
}
