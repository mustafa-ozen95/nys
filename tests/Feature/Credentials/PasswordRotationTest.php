<?php

declare(strict_types=1);

namespace Tests\Feature\Credentials;

use App\Enums\CredentialProtocol;
use App\Enums\PasswordRotationStage;
use App\Enums\PasswordRotationStatus;
use App\Models\ActivityLog;
use App\Models\NetworkSwitch;
use App\Services\Credentials\SwitchPasswordRotator;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosAccounts;
use Tests\TestCase;

/**
 * Cihaz parolasının değiştirilmesi.
 *
 * Sıra güvenliğin kendisidir ve buradaki sahte cihaz onu gerçekten
 * doğrular: yanlış parolayla oturum açılmaz, commit edilen parola cihazda
 * geçerli olur, silinen hesap bir daha giremez.
 *
 * Değiştirilen şey panelin cihaza ERİŞİMİDİR; yanlış giderse switch
 * yönetilemez hâle gelir. Bu yüzden her adım ayrı ayrı sınanır.
 */
final class PasswordRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['credentials.rotation.recovery_username' => 'nsm-kurtarma']);
    }

    public function test_parola_degisir_ve_kurtarma_hesabi_silinir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $rotation = $this->rotator()->rotate($switch);

        $this->assertSame(PasswordRotationStatus::Succeeded, $rotation->status);
        $this->assertSame(PasswordRotationStage::RecoveryRemoved, $rotation->stage);

        // Kurtarma hesabı cihazda kalmadı.
        $this->assertArrayNotHasKey('nsm-kurtarma', $device->accounts());
        $this->assertFalse($rotation->fresh()->recovery_active);

        // Cihazdaki parola değişti ve panelin kaydı onunla aynı.
        $this->assertNotSame('eskiparola', $device->accounts()['admin']);
        $this->assertSame($device->accounts()['admin'], $switch->fresh()->netconfCredential->password);
    }

    /**
     * Kurtarma hesabı TAM YETKİLİ olmalı: yarım yetkili bir hesapla cihaza
     * geri dönülemez, yapılandırma yazılamaz.
     */
    public function test_kurtarma_hesabi_tam_yetkili_acilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->rotator()->rotate($switch);

        $this->assertStringContainsString(
            '<user><name>nsm-kurtarma</name><class>super-user</class>',
            $device->payload(),
        );
    }

    /**
     * Parola KURTARMA hesabıyla değiştirilir.
     *
     * Kendi parolanı değiştirdiğin oturumla onay veremezsin: geri alınabilir
     * commit'in onayı YENİ bir oturumda verilir ve o oturum artık eski
     * parolayı kabul etmez. Sahte cihaz da reddediyor; bu yüzden test
     * sıralamayı gerçekten kanıtlar.
     */
    public function test_parola_degisimi_kurtarma_hesabiyla_atilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->rotator()->rotate($switch);

        $kurtarmayla = array_filter(
            $device->attempts,
            static fn (array $deneme): bool => $deneme['username'] === 'nsm-kurtarma' && $deneme['accepted'],
        );

        $this->assertNotEmpty($kurtarmayla, 'Kurtarma hesabıyla hiç oturum açılmamış.');

        // Reddedilen tek bir oturum denemesi bile olmamalı.
        $this->assertSame(
            [],
            array_filter($device->attempts, static fn (array $deneme): bool => ! $deneme['accepted']),
        );
    }

    /**
     * Tur başarıyla bittiğinde paneldeki sırlar silinir: cihazda karşılığı
     * kalmamış bir sırrı tutmanın hiçbir faydası yok.
     */
    public function test_tur_bitince_saklanan_sirlar_silinir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $rotation = $this->rotator()->rotate($switch)->fresh();

        $this->assertNull($rotation->new_password);
        $this->assertNull($rotation->recovery_password);
        $this->assertNotSame('eskiparola', $device->accounts()['admin']);
    }

    /**
     * Kurtarma hesabı açıldığı hâlde bağlanılamıyorsa güvence değil, açıkta
     * duran fazladan bir yetkidir: silinir ve tur durur.
     */
    public function test_calismayan_kurtarma_hesabi_silinir_ve_tur_durur(): void
    {
        $switch = $this->switch();
        $device = $this->device();
        $device->rejectOnNextPasswordChange('nsm-kurtarma');

        $rotation = $this->rotator()->rotate($switch);

        $this->assertSame(PasswordRotationStatus::Failed, $rotation->status);
        $this->assertSame(PasswordRotationStage::RecoveryCreated, $rotation->stage);
        $this->assertFalse($rotation->fresh()->recovery_active);
        $this->assertArrayNotHasKey('nsm-kurtarma', $device->accounts());

        // Panel hesabının parolasına dokunulmadı.
        $this->assertSame('eskiparola', $switch->fresh()->netconfCredential->password);
    }

    /**
     * Yeni parola denenemezse kurtarma hesabı cihazda BIRAKILIR: panelin
     * cihaza dönebileceği kapı açık kalmalı.
     */
    public function test_yeni_parola_denenemezse_kurtarma_hesabi_birakilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();
        $device->rejectOnNextPasswordChange('admin');

        $rotation = $this->rotator()->rotate($switch);

        $this->assertSame(PasswordRotationStatus::Failed, $rotation->status);
        $this->assertSame(PasswordRotationStage::PasswordChanged, $rotation->stage);
        $this->assertTrue($rotation->fresh()->recovery_active);
        $this->assertArrayHasKey('nsm-kurtarma', $device->accounts());

        /*
         * Cihazda yeni parola var ve panel onu KAYDINDA TUTUYOR: yazma ile
         * doğrulama arasında kaybolsaydı cihaza bir daha girilemezdi.
         */
        $this->assertSame($device->accounts()['admin'], $rotation->fresh()->new_password);

        // Kimlik kaydı değişmedi: doğrulanmamış parola kaydedilmez.
        $this->assertSame('eskiparola', $switch->fresh()->netconfCredential->password);
    }

    /**
     * Temizlenmemiş bir kurtarma hesabı varken yeni tur başlamaz: yenisi
     * eskisinin parolasını ezer ve cihaza dönüş kapısı kaybolurdu.
     */
    public function test_temizlenmemis_kurtarma_hesabi_yeni_turu_engeller(): void
    {
        $switch = $this->switch();
        $device = $this->device();
        $device->rejectOnNextPasswordChange('admin');

        $this->rotator()->rotate($switch);
        $ikinci = $this->rotator()->rotate($switch->fresh());

        $this->assertSame(PasswordRotationStatus::Failed, $ikinci->status);
        $this->assertStringContainsString('temizlenmemiş', (string) $ikinci->error);
    }

    /**
     * Kurtarma hesabının adı panel hesabıyla aynı olamaz: aynı olsaydı
     * kurtarma hesabını yaratmak asıl hesabın üstüne yazardı.
     */
    public function test_kurtarma_adi_panel_hesabiyla_ayni_olamaz(): void
    {
        config(['credentials.rotation.recovery_username' => 'admin']);

        $switch = $this->switch();
        $this->device();

        $rotation = $this->rotator()->rotate($switch);

        $this->assertSame(PasswordRotationStatus::Failed, $rotation->status);
        $this->assertStringContainsString('aynı olamaz', (string) $rotation->error);
    }

    public function test_kimligi_olmayan_switch_reddedilir(): void
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'kimliksiz']);
        $this->device();

        $rotation = $this->rotator()->rotate($switch);

        $this->assertSame(PasswordRotationStatus::Failed, $rotation->status);
        $this->assertStringContainsString('kimliği tanımlı değil', (string) $rotation->error);
    }

    /**
     * Cihazdaki hesap TEKTİR; SSH ile NETCONF satırları onun iki yüzüdür.
     * Biri güncellenip diğeri kalırsa uzak oturum açılamaz olur.
     */
    public function test_ayni_hesabin_butun_kimlik_satirlari_guncellenir(): void
    {
        $switch = $this->switch();
        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Ssh,
            'username' => 'admin',
            'password' => 'eskiparola',
            'port' => 22,
        ]);

        $device = $this->device();

        $this->rotator()->rotate($switch->fresh());

        $switch = $switch->fresh();

        $this->assertSame($device->accounts()['admin'], $switch->netconfCredential->password);
        $this->assertSame($device->accounts()['admin'], $switch->sshCredential->password);
    }

    /**
     * Parola kayda GİRMEZ: ne eskisi, ne yenisi, ne kurtarma hesabınınki.
     */
    public function test_parolalar_etkinlik_kaydina_dusmez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->rotator()->rotate($switch);

        $kayitlar = ActivityLog::query()->pluck('description')->implode(' ');

        $this->assertStringNotContainsString('eskiparola', $kayitlar);
        $this->assertStringNotContainsString($device->accounts()['admin'], $kayitlar);
    }

    public function test_onarim_calisan_panel_hesabiyla_kurtarmayi_siler(): void
    {
        $switch = $this->switch();
        $device = $this->device();
        $device->rejectOnNextPasswordChange('admin');

        $rotation = $this->rotator()->rotate($switch);
        $this->assertTrue($rotation->fresh()->recovery_active);

        // Cihaz yeniden oturum açtırıyor; paneldeki yeni parola geçerli.
        $device->acceptUser('admin');

        $onarilan = $this->rotator()->repair($rotation->fresh())->fresh();

        $this->assertFalse($onarilan->recovery_active);
        $this->assertArrayNotHasKey('nsm-kurtarma', $device->accounts());
        $this->assertSame($device->accounts()['admin'], $switch->fresh()->netconfCredential->password);

        /*
         * Onarılan tur artık başarılıdır ama neden onarıldığı okunabilir
         * kalır: ekranda kalıcı bir kırmızı satır, gerçekten bakım isteyen
         * cihazı görünmez kılardı.
         */
        $this->assertSame(PasswordRotationStatus::Succeeded, $onarilan->status);
        $this->assertStringStartsWith('onarıldı · ', (string) $onarilan->error);
    }

    /**
     * Panel hesabına bilinen hiçbir parolayla girilemiyorsa cihaza tek kapı
     * kurtarma hesabıdır: onunla yeni bir parola kurulur ve tur tamamlanır.
     */
    public function test_onarim_yalnizca_kurtarma_calisirken_parolayi_yeniden_kurar(): void
    {
        $switch = $this->switch();
        $device = $this->device();
        $device->rejectOnNextPasswordChange('admin');

        $rotation = $this->rotator()->rotate($switch);

        // Cihazdaki parola panelin bildiği hiçbir değerle uyuşmuyor.
        $device->acceptUser('admin');
        $device->setPassword('admin', 'elleDegistirildi9');

        $onarilan = $this->rotator()->repair($rotation->fresh());

        $this->assertFalse($onarilan->fresh()->recovery_active);
        $this->assertArrayNotHasKey('nsm-kurtarma', $device->accounts());
        $this->assertSame($device->accounts()['admin'], $switch->fresh()->netconfCredential->password);
        $this->assertNotSame('elleDegistirildi9', $device->accounts()['admin']);
    }

    public function test_hicbir_kapi_calismiyorsa_onarim_soyler(): void
    {
        $switch = $this->switch();
        $device = $this->device();
        $device->rejectOnNextPasswordChange('admin');

        $rotation = $this->rotator()->rotate($switch);

        $device->rejectUser('nsm-kurtarma');

        $this->expectException(NetconfException::class);
        $this->expectExceptionMessageMatches('/konsoldan/');

        $this->rotator()->repair($rotation->fresh());
    }

    private function rotator(): SwitchPasswordRotator
    {
        return app(SwitchPasswordRotator::class);
    }

    private function device(?FakeJunosAccounts $device = null): FakeJunosAccounts
    {
        $device ??= new FakeJunosAccounts(['admin' => 'eskiparola']);

        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'test1', 'management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'eskiparola',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
