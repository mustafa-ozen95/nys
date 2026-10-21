<?php

declare(strict_types=1);

namespace Tests\Feature\Sunucu;

use App\Enums\PasswordRotationStage;
use App\Enums\PasswordRotationStatus;
use App\Jobs\SunucuParolaDondurJob;
use App\Models\Server;
use App\Models\ServerPasswordRotation;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Sunucu\SunucuOturumu;
use App\Services\Sunucu\SunucuParolaDondurucu;
use App\Services\Sunucu\Surucu\SunucuSurucusu;
use App\Services\Sunucu\SurucuSecici;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sunucu parolasının değiştirilmesi.
 *
 * NYS'nin sunucuları kendine aittir ve parolalarını kendisi değiştirir.
 * GÜVENLİĞİ VEREN ŞEY SIRANIN KENDİSİDİR — bu yüzden sınanan şey "parola
 * değişti mi" değil, sıranın adımlarının doğru yerde ve doğru kimlikle
 * yapıldığıdır.
 *
 * Makineye BAĞLANILMAZ: sürücü sahtelenir. Gerçek SSH/WinRM yolunun kendi
 * ölçümleri sürücülerin içinde yazılı.
 */
final class SunucuParolaDondurmeTest extends TestCase
{
    use RefreshDatabase;

    private function sunucu(array $ustune = []): Server
    {
        return Server::factory()->create([
            'short_name' => 'LNX-09',
            'ip_address' => '198.18.20.9',
            'os_type' => 'linux',
            'connection_protocol' => 'ssh',
            'connection_port' => 22,
            'connection_username' => 'root',
            'connection_password' => 'ESKI-PAROLA',
            'status' => 'online',
            ...$ustune,
        ]);
    }

    /** Sürücüyü sahteleyip yerine koyar. */
    private function surucuyuSahtele(SunucuSurucusu $surucu): void
    {
        $secici = new class($surucu) extends SurucuSecici
        {
            public function __construct(private SunucuSurucusu $sahte) {}

            public function icin(Server $sunucu): SunucuSurucusu
            {
                return $this->sahte;
            }

            public function engel(Server $sunucu): ?string
            {
                return null;
            }
        };

        $this->app->instance(SurucuSecici::class, $secici);
    }

    public function test_sira_kurtarma_hesabiyla_isler_ve_parola_kayda_yazilir(): void
    {
        $sunucu = $this->sunucu();

        $surucu = new SahteSurucu;
        $this->surucuyuSahtele($surucu);

        $tur = app(SunucuParolaDondurucu::class)->dondur($sunucu, null, 'yil sonu');

        $this->assertSame(PasswordRotationStatus::Succeeded, $tur->status);
        $this->assertSame(PasswordRotationStage::RecoveryRemoved, $tur->stage);

        /*
         * SIRA: kurtarma hesabı AÇILIR, kanıtlanır, parola KURTARMA HESABIYLA
         * değiştirilir, yeni parola denenir ve hesap en sonda silinir.
         * Parolasını değiştirdiğimiz hesapla değiştirmek, iş yarıda kalırsa
         * bizi dışarıda bırakırdı.
         */
        $this->assertSame(
            ['kurtarmaAc', 'parolaDegistir', 'kurtarmaSil'],
            $surucu->yapilanlar,
        );

        $this->assertNotSame('root', $surucu->parolayiDegistiren, 'Parola kendi oturumundan değiştirilmemeli.');
        $this->assertStringStartsWith('nys-kurtarma-', (string) $surucu->parolayiDegistiren);

        /* Kayıttaki parola ancak DOĞRULANDIKTAN sonra değişir. */
        $sunucu->refresh();
        $this->assertNotSame('ESKI-PAROLA', $sunucu->connection_password);
        $this->assertSame($surucu->yazilanParola, $sunucu->connection_password);

        /* Kurtarma hesabı silindi: paneldeki sırrı da silinmeli. */
        $this->assertFalse($tur->recovery_active);
        $this->assertNull($tur->recovery_password);
    }

    public function test_kurtarma_hesabi_dogrulanamazsa_tur_iptal_edilir(): void
    {
        $sunucu = $this->sunucu();

        $surucu = new SahteSurucu;
        $surucu->kurtarmaDogrulanir = false;
        $this->surucuyuSahtele($surucu);

        $tur = app(SunucuParolaDondurucu::class)->dondur($sunucu);

        $this->assertSame(PasswordRotationStatus::Failed, $tur->status);

        /*
         * ÇALIŞMAYAN KURTARMA HESABI GÜVENCE DEĞİL, AÇIKTA DURAN FAZLADAN BİR
         * YETKİDİR: silinir ve asıl parolaya hiç dokunulmaz.
         */
        $this->assertContains('kurtarmaSil', $surucu->yapilanlar);
        $this->assertNotContains('parolaDegistir', $surucu->yapilanlar);

        $sunucu->refresh();
        $this->assertSame('ESKI-PAROLA', $sunucu->connection_password);
    }

    public function test_yeni_parola_dogrulanamazsa_kayit_degismez(): void
    {
        $sunucu = $this->sunucu();

        $surucu = new SahteSurucu;
        $surucu->yeniParolaDogrulanir = false;
        $this->surucuyuSahtele($surucu);

        $tur = app(SunucuParolaDondurucu::class)->dondur($sunucu);

        $this->assertSame(PasswordRotationStatus::Failed, $tur->status);

        /*
         * Kurtarma hesabı MAKİNEDE BIRAKILIR: makineye dönebileceğimiz kapı
         * açık kalmalı ve ekran bunu uyarı olarak göstermeli.
         */
        $this->assertTrue($tur->recovery_active);
        $this->assertNotNull($tur->recovery_password);

        $sunucu->refresh();
        $this->assertSame('ESKI-PAROLA', $sunucu->connection_password);
    }

    public function test_kurtarma_destegi_olmayan_platformda_dogrudan_degistirilir(): void
    {
        $sunucu = $this->sunucu(['os_type' => 'windows', 'connection_protocol' => 'rdp', 'connection_port' => 3389]);

        $surucu = new SahteSurucu;
        $surucu->kurtarma = false;
        $this->surucuyuSahtele($surucu);

        $tur = app(SunucuParolaDondurucu::class)->dondur($sunucu);

        $this->assertSame(PasswordRotationStatus::Succeeded, $tur->status);
        $this->assertSame(['parolaDegistir'], $surucu->yapilanlar);

        /* Parola kendi oturumundan değişir; başka kapı yok. */
        $this->assertSame('root', $surucu->parolayiDegistiren);

        $sunucu->refresh();
        $this->assertSame($surucu->yazilanParola, $sunucu->connection_password);
    }

    public function test_yarida_kalan_tur_onarimda_kapatilir(): void
    {
        $sunucu = $this->sunucu();

        $tur = ServerPasswordRotation::create([
            'server_id' => $sunucu->id,
            'status' => PasswordRotationStatus::Failed,
            'stage' => PasswordRotationStage::RecoveryCreated,
            'recovery_username' => 'nys-kurtarma-abc123',
            'recovery_password' => 'KURTARMA-PAROLA',
            'recovery_active' => true,
        ]);

        $surucu = new SahteSurucu;
        $this->surucuyuSahtele($surucu);

        $sonuc = app(SunucuParolaDondurucu::class)->onar($tur->fresh());

        $this->assertSame(PasswordRotationStatus::Succeeded, $sonuc->status);
        $this->assertFalse($sonuc->recovery_active);
        $this->assertNull($sonuc->recovery_password);
        $this->assertContains('kurtarmaSil', $surucu->yapilanlar);
    }

    public function test_yetkisi_olmayan_tur_baslatamaz(): void
    {
        $sunucu = $this->sunucu();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'servers', ['view', 'update']);

        $this->actingAs($user->fresh())
            ->post(route('servers.password', $sunucu), ['reason' => 'denetim'])
            ->assertForbidden();
    }

    public function test_tur_kuyruga_alinir(): void
    {
        Queue::fake();

        $sunucu = $this->sunucu();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'servers', ['view', 'rotate']);

        $this->actingAs($user->fresh())
            ->post(route('servers.password', $sunucu), ['reason' => 'denetim'])
            ->assertRedirect();

        Queue::assertPushed(SunucuParolaDondurJob::class);
    }
}

/**
 * Makineyle konuşmayan sürücü.
 *
 * Sıranın kendisini sınamak için yeterli: hangi işin hangi KİMLİKLE ve hangi
 * SIRAYLA yapıldığını kaydeder.
 */
final class SahteSurucu implements SunucuSurucusu
{
    /** @var list<string> */
    public array $yapilanlar = [];

    public bool $kurtarma = true;

    public bool $kurtarmaDogrulanir = true;

    public bool $yeniParolaDogrulanir = true;

    public ?string $parolayiDegistiren = null;

    public ?string $yazilanParola = null;

    private ?string $kurtarmaAdi = null;

    public function engel(Server $sunucu): ?string
    {
        return null;
    }

    public function kurtarmaDestekler(): bool
    {
        return $this->kurtarma;
    }

    public function ac(Server $sunucu, string $kullanici, string $parola): SunucuOturumu
    {
        return new SunucuOturumu(null, $kullanici, $parola, static fn () => null);
    }

    public function kurtarmaAc(SunucuOturumu $oturum, string $kullanici, string $parola): void
    {
        $this->yapilanlar[] = 'kurtarmaAc';
        $this->kurtarmaAdi = $kullanici;
    }

    public function parolaDegistir(SunucuOturumu $oturum, string $kullanici, string $yeniParola): void
    {
        $this->yapilanlar[] = 'parolaDegistir';
        $this->parolayiDegistiren = $oturum->kullanici;
        $this->yazilanParola = $yeniParola;
    }

    public function kurtarmaSil(SunucuOturumu $oturum, string $kullanici): void
    {
        $this->yapilanlar[] = 'kurtarmaSil';
    }

    public function dogrula(Server $sunucu, string $kullanici, string $parola): bool
    {
        if ($kullanici === $this->kurtarmaAdi) {
            return $this->kurtarmaDogrulanir;
        }

        /* Asıl hesap: yeni parola denenirken ayarlanabilir. */
        return $parola === $this->yazilanParola
            ? $this->yeniParolaDogrulanir
            : true;
    }
}
