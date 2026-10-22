<?php

declare(strict_types=1);

namespace Tests\Feature\Capabilities;

use App\Enums\CredentialProtocol;
use App\Enums\JunosSchemaProfile;
use App\Enums\PanelMode;
use App\Enums\PortMode;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Capabilities\CapabilityProbe;
use App\Services\Capabilities\DeviceCapabilities;
use App\Services\Capabilities\DeviceCapability;
use App\Services\Junos\JunosDialect;
use App\Services\Netconf\NetconfClient;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Bir alanın kabul ettiği DEĞERLER aileye değil modele bağlı olabiliyor:
 *
 *   speed 1g                  EX3200/12.3 KABUL · EX2300/24.4 RED
 *   instance-type forwarding  EX3200/12.3 KABUL · EX2300/24.4 RED
 *
 * Lehçeye yazmak, ölçülmemiş bir kısıtı bütün aileye dayatmak olurdu; cevabı
 * cihaz veriyor. Bu testler sondanın soruyu doğru sorduğunu, cevabın
 * saklandığını ve ekranın ona uyduğunu kilitler.
 */
final class DeviceCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sonda değerleri tek tek dener ve kabul edilenleri saklar.
     */
    public function test_sonda_kabul_edilen_degerleri_olcer(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5');
        $this->port($switch, 'ge-0/1/0');

        $device = $this->device(rejects: ['<ethernet-1g/>']);

        $measured = $this->probe($switch, $device);

        /*
         * Kısıt PIC BAŞINA: her kapsam ayrı ölçülür ve ayrı saklanır. Cihaz
         * genelinde saklansaydı bir PIC'te geçerli olan seçenek öbüründe
         * gizlenirdi.
         */
        $measuredSpeeds = $measured['port.speeds'];
        sort($measuredSpeeds);

        $this->assertSame(
            ['ge-0/0:10', 'ge-0/0:100', 'ge-0/1:10', 'ge-0/1:100'],
            $measuredSpeeds,
        );
    }

    /**
     * Reddedilen değer sonucun kendisidir; sonda yarıda kesilmez.
     */
    public function test_reddedilen_deger_sondayi_durdurmaz(): void
    {
        $switch = $this->switch();

        $device = $this->device(rejects: ['<instance-type>forwarding<']);

        $measured = $this->probe($switch, $device);

        $this->assertNotContains('forwarding', $measured['routing.instance_types']);
        $this->assertContains('virtual-router', $measured['routing.instance_types']);
    }

    /**
     * Sonda CİHAZDA İZ BIRAKMAZ: özel aday açılır, commit check bile
     * yapılmaz, aday atılır.
     */
    public function test_sonda_commit_etmez_ve_adayi_atar(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->probe($switch, $device);

        $written = implode('', $device->written);

        $this->assertStringContainsString('<open-configuration><private/>', $written);
        $this->assertStringContainsString('<close-configuration/>', $written);
        $this->assertStringNotContainsString('<commit-configuration>', $written);
    }

    /**
     * Ölçüm aynı sürümde tekrarlanmaz; sürüm değişince yenilenir.
     */
    public function test_olcum_surum_degisince_yenilenir(): void
    {
        $switch = $this->switch();
        $probe = app(CapabilityProbe::class);

        $this->assertTrue($probe->shouldProbe($switch), 'Hiç ölçülmemiş cihaz ölçülmeliydi.');

        $switch->forceFill([
            'device_capabilities' => [
                CapabilityProbe::VERSION_KEY => $switch->junos_version,
                'port.speeds' => ['10'],
            ],
        ])->save();

        $this->assertFalse($probe->shouldProbe($switch->fresh()), 'Aynı sürümde yeniden ölçülmemeliydi.');

        $switch->forceFill(['junos_version' => '24.4R2-S5'])->save();

        $this->assertTrue($probe->shouldProbe($switch->fresh()), 'Sürüm değişince ölçüm yenilenmeliydi.');
    }

    /**
     * HİÇ ÖLÇÜLMEMİŞ cihazda seçenek KISITLANMAZ. Sonda çalışmadı diye
     * çalışan bir özelliğin kaybolması, yanlış değeri denemekten kötüdür.
     */
    public function test_olculmemis_cihazda_liste_daralmaz(): void
    {
        $switch = $this->switch();
        $capabilities = app(DeviceCapabilities::class);

        $this->assertNull($capabilities->values($switch, 'port.speeds'));
        $this->assertNull($capabilities->speedsFor($switch, 'ge-0/0/5'));

        $this->assertSame(
            ['10' => 'a', '1000' => 'b'],
            $capabilities->filterSpeeds($switch, 'ge-0/0/5', ['10' => 'a', '1000' => 'b']),
        );
    }

    public function test_olculmus_cihazda_liste_daralir(): void
    {
        $switch = $this->switch();
        $switch->forceFill([
            'device_capabilities' => ['port.speeds' => ['ge-0/0:10', 'ge-0/0:100']],
        ])->save();

        $capabilities = app(DeviceCapabilities::class);

        $this->assertSame(['10', '100'], $capabilities->speedsFor($switch->fresh(), 'ge-0/0/5'));
        $this->assertSame(
            ['10' => 'a'],
            $capabilities->filterSpeeds($switch->fresh(), 'ge-0/0/5', ['10' => 'a', '1000' => 'b']),
        );

        /*
         * ÖLÇÜLMEMİŞ bir PIC daraltılmaz: aynı cihazın SFP yuvası bakır
         * portla aynı kısıta tabi değil.
         */
        $this->assertNull($capabilities->speedsFor($switch->fresh(), 'ge-0/1/0'));
    }

    /**
     * Sürüm anahtarı bir yetenek DEĞİLDİR; yetenek gibi okunmaz.
     */
    public function test_surum_anahtari_yetenek_sayilmaz(): void
    {
        $switch = $this->switch();
        $switch->forceFill([
            'device_capabilities' => [CapabilityProbe::VERSION_KEY => '24.4R2-S4'],
        ])->save();

        $this->assertNull(app(DeviceCapabilities::class)->values($switch->fresh(), CapabilityProbe::VERSION_KEY));
    }

    /**
     * Ekran cihazın reddettiği hızı SUNMAZ.
     */
    public function test_ekran_reddedilen_hizi_sunmaz(): void
    {
        $switch = $this->switch();
        $switch->forceFill([
            'device_capabilities' => ['port.speeds' => ['ge-0/0:10', 'ge-0/0:100']],
        ])->save();
        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'port_mode' => PortMode::Access,
        ]);

        // Ekran trafik ölçümü de okuyor; bu test onu ölçmüyor.
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'result' => [], 'id' => 1])]);

        $response = $this->actingAs($this->operator())
            ->get(route('switches.ports', $switch))
            ->assertOk();

        $options = $response->viewData('ports')->firstWhere('model.name', 'ge-0/0/5')['speed_options'];

        $this->assertArrayNotHasKey('1000', $options);
        $this->assertArrayHasKey('100', $options);
    }

    /**
     * Eksik hız AÇIKLANIR; sessizce yok olmaz.
     *
     * Portu 1 Gbps çalışan bir cihazda 1000 seçeneğinin bulunmaması hata
     * sanıldı. Cihaz haklı: 1000BASE-T bakırda zorla ayarlanamaz, otomatik
     * anlaşmayla kurulur. Panelin bunu SÖYLEMESİ gerekiyordu.
     */
    public function test_eksik_hiz_ekranda_aciklanir(): void
    {
        $switch = $this->switch();
        $switch->forceFill([
            'device_capabilities' => ['port.speeds' => ['ge-0/0:10', 'ge-0/0:100']],
        ])->save();

        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'port_mode' => PortMode::Access,
        ]);

        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'result' => [], 'id' => 1])]);

        $this->actingAs($this->operator())
            ->get(route('switches.ports', $switch))
            ->assertOk()
            ->assertSee('otomatik anlaşmayla kurulur', false);
    }

    /**
     * 1000 sunulabiliyorsa fazladan açıklama yapılmaz.
     */
    public function test_hiz_tamsa_fazladan_aciklama_yok(): void
    {
        $switch = $this->switch();
        $switch->forceFill([
            'device_capabilities' => ['port.speeds' => ['ge-0/0:10', 'ge-0/0:100', 'ge-0/0:1000']],
        ])->save();

        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'port_mode' => PortMode::Access,
        ]);

        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'result' => [], 'id' => 1])]);

        $this->actingAs($this->operator())
            ->get(route('switches.ports', $switch))
            ->assertOk()
            ->assertDontSee('otomatik anlaşmayla kurulur', false);
    }

    /**
     * Elle gönderilen değer de reddedilir; ekranın daraltması yetmez.
     */
    public function test_reddedilen_hiz_sunucuda_da_yazilamaz(): void
    {
        $switch = $this->switch();
        $switch->forceFill([
            'device_capabilities' => ['port.speeds' => ['ge-0/0:10', 'ge-0/0:100']],
        ])->save();

        $port = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'port_mode' => PortMode::Access,
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Access->value,
                'speed_config' => '1000',
            ])
            ->assertSessionHasErrors('speed_config');

        $this->assertNull($device->connection, 'Reddedilen değer cihaza gitmemeliydi.');
    }

    /**
     * Daraltma bir seçeneği GİZLEYEBİLİR, var olan bir ayarı SİLEMEZ.
     *
     * Cihazda zaten 1000 yazılı bir port, liste daraldığında seçili değerini
     * kaybederdi ve kaydeden kullanıcı farkında olmadan otomatik anlaşmaya
     * geçerdi. Bu projede tekrarlayan tuzak: "gönderilmeyen = silinsin".
     */
    public function test_cihazda_yazili_hiz_listeden_dusmez(): void
    {
        $switch = $this->switch();
        $switch->forceFill([
            'device_capabilities' => ['port.speeds' => ['ge-0/0:10', 'ge-0/0:100']],
        ])->save();

        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'port_mode' => PortMode::Access,
            'speed_config' => '1000',
        ]);

        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'result' => [], 'id' => 1])]);

        $options = $this->actingAs($this->operator())
            ->get(route('switches.ports', $switch))
            ->assertOk()
            ->viewData('ports')
            ->firstWhere('model.name', 'ge-0/0/5')['speed_options'];

        $this->assertArrayHasKey('1000', $options);
        $this->assertStringContainsString('cihazda tanımlı', $options['1000']);
    }

    /**
     * O portta yazılı olan değer kaydedilebilir kalmalı; yoksa kullanıcı
     * hıza hiç dokunmasa bile portu düzenleyemezdi.
     */
    public function test_cihazda_yazili_hiz_kaydedilebilir_kalir(): void
    {
        $switch = $this->switch();
        $switch->forceFill([
            'device_capabilities' => ['port.speeds' => ['ge-0/0:10', 'ge-0/0:100']],
        ])->save();

        $port = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'port_mode' => PortMode::Access,
            'speed_config' => '1000',
        ]);

        $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Access->value,
                'speed_config' => '1000',
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * Yeni bir yetenek eklemek TEK sınıf ve config'te TEK satır olmalı.
     *
     * Bu kilit olmadan bir sonraki kişi soruyu doğrudan servise yazar ve
     * yapı sessizce dağılır (bkz. .ai/rules/modularity.md).
     */
    public function test_yetenek_eklemek_tek_satir(): void
    {
        $fake = new class implements DeviceCapability
        {
            public function key(): string
            {
                return 'deneme.degerler';
            }

            public function label(): string
            {
                return 'Deneme';
            }

            public function candidates(JunosDialect $dialect, NetworkSwitch $switch): array
            {
                return ['evet' => '<system><host-name>sonda</host-name></system>'];
            }
        };

        app()->instance($fake::class, $fake);
        config(['capabilities.probes' => [$fake::class]]);

        $switch = $this->switch();
        $measured = $this->probe($switch, $this->device());

        $this->assertSame(['evet'], $measured['deneme.degerler']);
    }

    /**
     * @param  list<string>  $rejects
     */
    private function device(array $rejects = []): FakeJunosDevice
    {
        $device = new FakeJunosDevice(loadRejects: $rejects);
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(NetworkSwitch $switch, FakeJunosDevice $device): array
    {
        $measured = null;

        app(NetconfClient::class)->run(
            $switch,
            function (NetconfSession $session) use ($switch, &$measured): void {
                $measured = app(CapabilityProbe::class)->detect($session, $switch);
            },
        );

        $this->assertIsArray($measured, 'Sonda cevap vermedi.');

        return $measured;
    }

    private function port(NetworkSwitch $switch, string $name): SwitchPort
    {
        return SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => $name,
            'port_mode' => PortMode::Access,
        ]);
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'management_ip' => '198.18.20.195',
            'schema_profile' => JunosSchemaProfile::PreEls,
            'junos_version' => '12.3R12.4',
        ]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    private function operator(PanelMode $mode = PanelMode::Advanced): User
    {
        $user = User::factory()->create(['panel_mode' => $mode]);
        /* Gelişmiş mod rol yetkisine bağlı. */
        app(UserPermissionResolver::class)->syncResource($user, 'gorunum_modu', ['view']);
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }
}
