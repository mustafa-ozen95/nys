<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Services\Netconf\NetconfClient;
use App\Services\Netconf\PortConfigurator;
use App\Services\Netconf\SchemaProbe;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Cihazın şema ailesi (ELS öncesi / ELS).
 *
 * Juniper EX ailesinde şemayı bir kez değiştirdi: aynı ayar farklı adla
 * yazılıyor. EX4200/EX3200 (12.3R12.4) ELS ÖNCESİdir; EX2300/3400/
 * 4300 ve QFX ELS'tir. Panel yanlış adı yazarsa o cihazı hiç yönetemez.
 *
 * Profil CİHAZA SORULARAK bulunur, sürüm tablosundan tahmin edilmez.
 * Ayırt edici LOAD adımıdır (jn32-test üzerinde ölçüldü):
 *
 *   bilinmeyen etiket         -> LOAD "syntax error" ile düşer
 *   bilinen ama anlamsız ayar -> LOAD geçer, commit check'te düşer
 *
 * "interface-mode" ayırt edici DEĞİLDİR: 12.3R12.4 onu da kabul ediyor.
 */
final class SchemaProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_port_mode_kabul_edilirse_els_oncesi(): void
    {
        $this->assertSame(JunosSchemaProfile::PreEls, $this->probe(new FakeJunosDevice));
    }

    /**
     * ELS cihazda "port-mode" YOKTUR; yükleme sözdizimi hatasıyla düşer.
     */
    public function test_sozdizimi_hatasi_els_demektir(): void
    {
        $this->assertSame(
            JunosSchemaProfile::Els,
            $this->probe(new FakeJunosDevice(loadError: 'syntax error | syntax error')),
        );
    }

    /**
     * Başka bir hata şema hakkında bir şey SÖYLEMEZ: erişim, kilit ya da
     * geçici bir sorun olabilir. Bilinmiyor bırakılır ve sonra yeniden
     * sorulur; yanlış bir profili kalıcı yazmak cihazı yönetilemez kılardı.
     */
    public function test_baska_hata_profili_belirlemez(): void
    {
        $this->assertNull(
            $this->probe(new FakeJunosDevice(loadError: 'permission denied')),
        );
    }

    public function test_els_oncesi_cihaza_port_mode_yazilir(): void
    {
        $belgeler = app(PortConfigurator::class)->documents(
            'ge-0/0/5',
            ['mode' => PortMode::Access],
            JunosSchemaProfile::PreEls,
        );

        $this->assertStringContainsString('<port-mode>access</port-mode>', implode('', $belgeler));
    }

    public function test_els_cihaza_interface_mode_yazilir(): void
    {
        $belgeler = app(PortConfigurator::class)->documents(
            'ge-0/0/5',
            ['mode' => PortMode::Access],
            JunosSchemaProfile::Els,
        );

        $metin = implode('', $belgeler);

        $this->assertStringContainsString('<interface-mode>access</interface-mode>', $metin);
        $this->assertStringNotContainsString('<port-mode>', $metin);
    }

    /**
     * Profil bilinmiyorsa ELS ÖNCESİ varsayılır: bu, profil eklenmeden
     * önceki davranıştır.
     */
    public function test_profil_bilinmiyorsa_els_oncesi_varsayilir(): void
    {
        $switch = NetworkSwitch::factory()->create(['schema_profile' => null]);

        $this->assertStringContainsString(
            '<port-mode>trunk</port-mode>',
            implode('', app(PortConfigurator::class)->documents(
                'ge-0/0/5',
                ['mode' => PortMode::Trunk],
                $switch->schema_profile,
            )),
        );
    }

    /**
     * Yazma yolu switchin profilini kullanır; elle geçirilen bir değer değil.
     */
    public function test_yazma_yolu_switchin_profilini_kullanir(): void
    {
        $switch = NetworkSwitch::factory()->create(['schema_profile' => JunosSchemaProfile::Els]);
        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        $switch = $switch->refresh();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/5']);

        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        app(PortConfigurator::class)->apply($switch, $port, ['mode' => PortMode::Access]);

        $this->assertStringContainsString('<interface-mode>access</interface-mode>', $device->payload());
    }

    private function probe(FakeJunosDevice $device): ?JunosSchemaProfile
    {
        $this->app->instance(NetconfTransport::class, $device);

        $switch = NetworkSwitch::factory()->create();
        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return app(NetconfClient::class)->run(
            $switch->refresh(),
            fn ($session) => app(SchemaProbe::class)->detect($session),
        );
    }
}
