<?php

declare(strict_types=1);

namespace App\Services\Sunucu;

use App\Enums\ActivityChannel;
use App\Enums\PasswordRotationStage;
use App\Enums\PasswordRotationStatus;
use App\Models\Server;
use App\Models\ServerPasswordRotation;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Sunucu\Surucu\SunucuSurucusu;
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
 * bir taviz değil, platformun dayattığı sınırdır (bkz. WindowsSurucusu).
 */
class SunucuParolaDondurucu
{
    /** Kayıt başına döndürme kilidi. */
    private const KILIT = 'nys:sunucu-dondurme:';

    public function __construct(
        private SurucuSecici $secici,
        private PasswordGenerator $uretici,
        private ActivityRecorder $activity,
    ) {}

    public function dondur(Server $sunucu, ?User $kullanici = null, ?string $gerekce = null): ServerPasswordRotation
    {
        $tur = $sunucu->passwordRotations()->create([
            'user_id' => $kullanici?->id,
            'status' => PasswordRotationStatus::Running,
            'stage' => PasswordRotationStage::Started,
            'started_at' => now(),
        ]);

        $engel = $this->secici->engel($sunucu);

        if ($engel !== null) {
            return $this->bitir($sunucu, $tur, PasswordRotationStatus::Failed, $engel, $kullanici, $gerekce);
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
        $kilit = Cache::lock(self::KILIT.$sunucu->getKey(), $this->kilitSaniye());

        if (! $kilit->get()) {
            return $this->bitir(
                $sunucu,
                $tur,
                PasswordRotationStatus::Failed,
                'Bu sunucu için bir güncelleme zaten sürüyor; ikinci tur başlatılmadı.',
                $kullanici,
                $gerekce,
            );
        }

        try {
            $this->calistir($sunucu, $tur, $kullanici, $gerekce);
        } catch (Throwable $e) {
            return $this->dustu($sunucu, $tur, $e, $kullanici, $gerekce);
        } finally {
            /* Tur nasıl biterse bitsin kilit BIRAKILIR. */
            $kilit->release();
        }

        return $tur->refresh();
    }

    /**
     * Yarıda kalmış bir turu kapatır.
     *
     * Hangi kimliğin geçerli olduğu BİLİNMEZ — tur nerede takıldığına göre
     * eski parola, yeni parola ya da yalnızca kurtarma hesabı çalışıyor
     * olabilir. Üçü de sırayla denenir; ilk açılan oturum yolu belirler.
     */
    public function onar(ServerPasswordRotation $tur, ?User $kullanici = null): ServerPasswordRotation
    {
        $sunucu = $tur->server;

        if (! $tur->recovery_active || $sunucu === null) {
            return $tur;
        }

        /*
         * Onarım da AYNI KİLİDİ alır. Onarım da makineye bağlanır, kimlik
         * dener ve kurtarma hesabını siler; sürmekte olan bir turun ortasında
         * bunu yapmak, o turun az önce açtığı hesabı silmek demekti.
         */
        $kilit = Cache::lock(self::KILIT.$sunucu->getKey(), $this->kilitSaniye());

        if (! $kilit->get()) {
            $this->yaz($sunucu, $kullanici, null, 'server_password_repair_deferred',
                "{$sunucu->short_name}: bir güncelleme sürüyor, onarım şimdi yapılmadı.");

            return $tur;
        }

        try {
            return $this->onarimiCalistir($sunucu, $tur, $kullanici);
        } finally {
            $kilit->release();
        }
    }

    private function calistir(
        Server $sunucu,
        ServerPasswordRotation $tur,
        ?User $kullanici,
        ?string $gerekce,
    ): void {
        $surucu = $this->secici->icin($sunucu);

        $surucu->kurtarmaDestekler()
            ? $this->kurtarmaliSira($sunucu, $tur, $surucu, $kullanici, $gerekce)
            : $this->dogrudanSira($sunucu, $tur, $surucu, $kullanici, $gerekce);
    }

    /**
     * Kurtarma hesaplı sıra — parolasını değiştirdiğimiz hesapla bağlandığımız
     * platformlar için (Linux/SSH).
     */
    private function kurtarmaliSira(
        Server $sunucu,
        ServerPasswordRotation $tur,
        SunucuSurucusu $surucu,
        ?User $kullanici,
        ?string $gerekce,
    ): void {
        $kurtarmaAdi = $this->kurtarmaAdi();
        $kurtarmaParolasi = $this->yeniParola();

        /*
         * Kurtarma KİMLİĞİ makineye gitmeden önce kayda geçer: hesap açılırken
         * süreç ölürse makinede parolasını bilmediğimiz bir hesap kalırdı.
         */
        $tur->forceFill([
            'recovery_username' => $kurtarmaAdi,
            'recovery_password' => $kurtarmaParolasi,
        ])->save();

        $ad = (string) $sunucu->connection_username;
        $parola = (string) $sunucu->connection_password;

        // 1 — kurtarma hesabı.
        $oturum = $surucu->ac($sunucu, $ad, $parola);

        try {
            /*
             * "MAKİNEDE HESAP DURUYOR" BAYRAĞI ANCAK BURADA KALKAR: oturum hiç
             * açılamadıysa makinede bir şey yoktur ve yanlış alarm verilmez.
             * Bayrak yine de hesabın açılmasından ÖNCE kalkar — açılış yarıda
             * kesilirse hesap makinede kalmış olabilir ve o ihtimalde
             * susmaktansa fazladan uyarmak doğrudur.
             */
            $tur->forceFill(['recovery_active' => true])->save();

            $surucu->kurtarmaAc($oturum, $kurtarmaAdi, $kurtarmaParolasi);
            $tur->forceFill(['stage' => PasswordRotationStage::RecoveryCreated])->save();
        } finally {
            $oturum->kapat();
        }

        // 2 — kurtarma hesabı gerçekten çalışıyor mu.
        if (! $surucu->dogrula($sunucu, $kurtarmaAdi, $kurtarmaParolasi)) {
            $this->kurtarmayiSil($sunucu, $tur, $surucu, $ad, $parola);

            throw new DondurmeHatasi('Kurtarma hesabı açıldı ama onunla bağlanılamadı; tur iptal edildi.');
        }

        $tur->forceFill(['stage' => PasswordRotationStage::RecoveryVerified])->save();

        // 3 — parola değişimi, KURTARMA hesabıyla.
        $yeni = $this->yeniParola();
        $tur->forceFill(['new_password' => $yeni])->save();

        $kurtarma = $surucu->ac($sunucu, $kurtarmaAdi, $kurtarmaParolasi);

        try {
            $surucu->parolaDegistir($kurtarma, $ad, $yeni);
            $tur->forceFill(['stage' => PasswordRotationStage::PasswordChanged])->save();
        } finally {
            $kurtarma->kapat();
        }

        // 4 — yeni parola deneniyor.
        if (! $surucu->dogrula($sunucu, $ad, $yeni)) {
            throw new DondurmeHatasi('Yeni parola makineye yazıldı ama onunla bağlanılamadı.');
        }

        $tur->forceFill(['stage' => PasswordRotationStage::PasswordVerified])->save();

        // 5 — paneldeki kayıt ancak şimdi değişir.
        $this->parolayiYaz($sunucu, $yeni);

        // 6 — kurtarma hesabı YENİ parolayla silinir.
        $this->kurtarmayiSil($sunucu, $tur, $surucu, $ad, $yeni);

        $tur->forceFill(['stage' => PasswordRotationStage::RecoveryRemoved])->save();

        $this->bitir(
            $sunucu, $tur, PasswordRotationStatus::Succeeded,
            'Parola güncellendi, doğrulandı ve kurtarma hesabı silindi.',
            $kullanici, $gerekce,
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
    private function dogrudanSira(
        Server $sunucu,
        ServerPasswordRotation $tur,
        SunucuSurucusu $surucu,
        ?User $kullanici,
        ?string $gerekce,
    ): void {
        $ad = (string) $sunucu->connection_username;
        $onceki = (string) $sunucu->connection_password;
        $yeni = $this->yeniParola();

        $tur->forceFill(['new_password' => $yeni])->save();

        $oturum = $surucu->ac($sunucu, $ad, $onceki);

        try {
            $surucu->parolaDegistir($oturum, $ad, $yeni);
            $tur->forceFill(['stage' => PasswordRotationStage::PasswordChanged])->save();
        } finally {
            $oturum->kapat();
        }

        if ($surucu->dogrula($sunucu, $ad, $yeni)) {
            $tur->forceFill(['stage' => PasswordRotationStage::PasswordVerified])->save();
            $this->parolayiYaz($sunucu, $yeni);

            $this->bitir(
                $sunucu, $tur, PasswordRotationStatus::Succeeded,
                'Parola güncellendi ve doğrulandı.',
                $kullanici, $gerekce,
            );

            return;
        }

        /* Doğrulanamadı: aynı kanaldan eskiye dön. */
        if ($onceki !== '') {
            $geri = $surucu->ac($sunucu, $ad, $yeni);

            try {
                $surucu->parolaDegistir($geri, $ad, $onceki);
            } finally {
                $geri->kapat();
            }

            if ($surucu->dogrula($sunucu, $ad, $onceki)) {
                $this->bitir(
                    $sunucu, $tur, PasswordRotationStatus::Failed,
                    'Yeni parola doğrulanamadı; eski parola geri kondu ve çalışıyor.',
                    $kullanici, $gerekce,
                );

                return;
            }
        }

        throw new DondurmeHatasi('Yeni parola doğrulanamadı ve eskisi geri konamadı.');
    }

    private function onarimiCalistir(
        Server $sunucu,
        ServerPasswordRotation $tur,
        ?User $kullanici,
    ): ServerPasswordRotation {
        $surucu = $this->secici->icin($sunucu);
        $ad = (string) $sunucu->connection_username;

        /*
         * Kayıttaki parola ÖNCE denenir. Tur 3. adımdan sonra takıldıysa
         * makinedeki parola yeni olabilir; o yüzden denenen parola da listede.
         * Sonuncu çare kurtarma hesabının kendisi.
         */
        $adaylar = [
            [$ad, (string) $sunucu->connection_password],
            [$ad, (string) $tur->new_password],
            [(string) $tur->recovery_username, (string) $tur->recovery_password],
        ];

        foreach ($adaylar as [$kim, $parola]) {
            if ($kim === '' || $parola === '') {
                continue;
            }

            if (! $surucu->dogrula($sunucu, $kim, $parola)) {
                continue;
            }

            /*
             * Makinedeki parola denenen parolaysa panel geride kalmış demektir;
             * ikisi ayrışmış hâlde bırakılmaz.
             */
            if ($kim === $ad && $parola === (string) $tur->new_password) {
                $this->parolayiYaz($sunucu, $parola);
            }

            try {
                $this->kurtarmayiSil($sunucu, $tur, $surucu, $kim, $parola);
            } catch (Throwable $e) {
                return $this->bitir(
                    $sunucu, $tur, PasswordRotationStatus::Failed,
                    'Kurtarma hesabı silinemedi: '.$e->getMessage(),
                    $kullanici, null,
                );
            }

            $tur->forceFill(['stage' => PasswordRotationStage::RecoveryRemoved])->save();

            return $this->bitir(
                $sunucu, $tur, PasswordRotationStatus::Succeeded,
                'Tur kapatıldı; kurtarma hesabı silindi.',
                $kullanici, null,
            );
        }

        return $this->bitir(
            $sunucu, $tur, PasswordRotationStatus::Failed,
            'Hiçbir kimlikle bağlanılamadı. Makineye elle girip kurtarma hesabını silmek gerekiyor.',
            $kullanici, null,
        );
    }

    /** Kurtarma hesabını siler ve panelden sırrını temizler. */
    private function kurtarmayiSil(
        Server $sunucu,
        ServerPasswordRotation $tur,
        SunucuSurucusu $surucu,
        string $kullaniciAdi,
        string $parola,
    ): void {
        if (blank($tur->recovery_username)) {
            return;
        }

        $oturum = $surucu->ac($sunucu, $kullaniciAdi, $parola);

        try {
            $surucu->kurtarmaSil($oturum, (string) $tur->recovery_username);
        } finally {
            $oturum->kapat();
        }

        $tur->kurtarmayiTemizle();
    }

    /**
     * Paneldeki kaydı yeni parolayla günceller.
     *
     * Sunucunun TEK kimliği var (connection_username/password); ayrı bir
     * döndürme hesabı yok, bu yüzden yayılacak başka bir alan da yok.
     */
    private function parolayiYaz(Server $sunucu, string $yeniParola): void
    {
        $sunucu->forceFill(['connection_password' => $yeniParola])->save();
    }

    private function dustu(
        Server $sunucu,
        ServerPasswordRotation $tur,
        Throwable $e,
        ?User $kullanici,
        ?string $gerekce,
    ): ServerPasswordRotation {
        return $this->bitir($sunucu, $tur, PasswordRotationStatus::Failed, $e->getMessage(), $kullanici, $gerekce);
    }

    private function bitir(
        Server $sunucu,
        ServerPasswordRotation $tur,
        PasswordRotationStatus $durum,
        string $mesaj,
        ?User $kullanici,
        ?string $gerekce,
    ): ServerPasswordRotation {
        $tur->forceFill([
            'status' => $durum,
            'error' => $durum === PasswordRotationStatus::Succeeded ? null : Str::limit($mesaj, 500),
            'finished_at' => now(),
        ])->save();

        $this->yaz(
            $sunucu,
            $kullanici,
            $gerekce,
            $durum === PasswordRotationStatus::Succeeded ? 'server_password_rotated' : 'server_password_rotation_failed',
            "{$sunucu->short_name}: {$mesaj}",
        );

        return $tur->refresh();
    }

    private function yaz(
        Server $sunucu,
        ?User $kullanici,
        ?string $gerekce,
        string $eylem,
        string $metin,
    ): void {
        $this->activity->recordAs(
            $kullanici?->id,
            $gerekce,
            request()->ip(),
            $sunucu,
            $eylem,
            $metin,
            ActivityChannel::Crud,
        );
    }

    private function yeniParola(): string
    {
        return $this->uretici->generate((int) config('sunucu.parola_uzunlugu', 24));
    }

    /**
     * Kurtarma hesabının adı her turda FARKLIDIR.
     *
     * Sabit bir ad, yarıda kalmış eski bir turun hesabıyla çakışır ve
     * "useradd: user already exists" hatası turu daha ilk adımda düşürürdü.
     */
    private function kurtarmaAdi(): string
    {
        return 'nys-kurtarma-'.Str::lower(Str::random(6));
    }

    private function kilitSaniye(): int
    {
        return (int) config('sunucu.kilit_saniye', 600);
    }
}
