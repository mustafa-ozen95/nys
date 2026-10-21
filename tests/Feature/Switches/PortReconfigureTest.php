<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\FirewallFamily;
use App\Enums\PortMode;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchFirewallFilter;
use App\Models\SwitchIpAddress;
use App\Models\SwitchPort;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Anahtarlamaya çevrilen bir port SONRADAN YENİDEN KURULABİLMELİ.
 *
 * Panel "Access seçerseniz IP yapılandırması silinir" diye uyarıyordu ama
 * uyarmak yetmiyor: kaydeden kullanıcının geri dönüş yolu yoktu. Mod
 * listesinde "Yönlendirilmiş" hiç bulunmuyordu ve arayüze bağlı firewall
 * filtresi okunuyor ama YAZILAMIYORDU — silinen "filter input KORUMA" satırı
 * panelden bir daha kurulamıyordu.
 *
 * Cihazda ölçüldü (test1, commit check): adressiz "family inet" kabul
 * ediliyor, filtre bağlaması "family inet" altında input ve output olarak
 * yazılabiliyor.
 */
final class PortReconfigureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mod listesi ÜÇ modu da sunar; "Yönlendirilmiş" eksikti ve bu yüzden
     * anahtarlamaya çevrilen bir port panelden geri alınamıyordu.
     */
    public function test_yonlendirilmis_mod_secilebilir(): void
    {
        // Ekran trafik için Zabbix'e soruyor; bu testin konusu o değil.
        Http::preventStrayRequests(false);

        $switch = $this->switch();
        $this->port($switch, PortMode::Access);

        $this->actingAs($this->operator())
            ->get(route('switches.ports', $switch))
            ->assertOk()
            ->assertSee('value="routed"', escape: false);
    }

    /**
     * Yönlendirmeye dönüş: anahtarlama ailesi kalkar, inet ailesi kurulur.
     * Adres henüz yoktur; cihaz adressiz "family inet"i kabul ediyor.
     */
    public function test_port_yonlendirmeye_geri_alinir(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, PortMode::Access);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Routed->value,
                'enabled' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<ethernet-switching delete="delete"/>', $payload);
        $this->assertStringContainsString('<inet>', $payload);

        // Aile hem silinip hem yazılmamalı: "drop_inet" burada geçerli değil.
        $this->assertStringNotContainsString('<inet delete="delete"/>', $payload);
    }

    /**
     * Silinen filtre bağlaması panelden geri kurulabilir.
     */
    public function test_filtre_baglamasi_geri_kurulabilir(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, PortMode::Access);

        SwitchFirewallFilter::factory()->for($switch, 'switch')->create([
            'name' => 'koruma',
            'family' => FirewallFamily::Inet,
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Routed->value,
                'enabled' => '1',
                'input_filter' => 'koruma',
                'output_filter' => 'koruma',
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString(
            '<filter><input><filter-name>koruma</filter-name></input>'
            .'<output><filter-name>koruma</filter-name></output></filter>',
            $payload,
        );
    }

    /**
     * Boş bırakmak bağlamayı KALDIRIR; "dokunma" değil "kaldır" demektir.
     */
    public function test_bos_filtre_baglamayi_kaldirir(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, PortMode::Routed);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Routed->value,
                'enabled' => '1',
                'input_filter' => '',
                'output_filter' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<input delete="delete"/>', $device->payload());
    }

    /**
     * Başka switchin filtresi bağlanamaz; ad tek başına yeterli değildir.
     */
    public function test_baska_switchin_filtresi_baglanamaz(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, PortMode::Access);

        SwitchFirewallFilter::factory()->for(NetworkSwitch::factory()->create(), 'switch')->create([
            'name' => 'yabanci',
            'family' => FirewallFamily::Inet,
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Routed->value,
                'enabled' => '1',
                'input_filter' => 'yabanci',
            ])
            ->assertSessionHasErrors('input_filter');

        $this->assertNull($device->connection);
    }

    /**
     * Yönlendirme modunda VLAN üyeliği olamaz: cihaz aynı arayüzde iki
     * aileyi kabul etmiyor. Panel bunu önce söyler.
     */
    public function test_yonlendirilmis_portta_vlan_verilemez(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, PortMode::Access);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['name' => 'ofis', 'vlan_tag' => 10]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Routed->value,
                'enabled' => '1',
                'vlans' => [$vlan->uuid],
            ])
            ->assertSessionHasErrors('vlans');

        $this->assertNull($device->connection);
    }

    /**
     * Anahtarlamaya geçerken aile HÂLÂ düşüyor; bu davranış korunmalı.
     */
    public function test_anahtarlamaya_gecerken_aile_hala_duser(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, PortMode::Routed);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Access->value,
                'enabled' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<inet delete="delete"/>', $device->payload());
    }

    /**
     * ADRES PORT EKRANINDAN DA VERİLEBİLİR.
     *
     * IP adresleri ekranı zaten vardı ama portu yönlendirmeye çevirip başka
     * bir ekrana gitmek gereksiz bir adım. Adres AYNI COMMIT'te yazılır:
     * port bir an adressiz kalmaz.
     */
    public function test_adres_port_ekranindan_verilebilir(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, PortMode::Access);

        AddressBlock::factory()->network('10.9.9.0/24')->create(['core_switch_id' => $switch->id]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Routed->value,
                'enabled' => '1',
                'ip_address' => '10.9.9.7',
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        // Mod değişimi ve adres AYNI belgede.
        $this->assertStringContainsString('<ethernet-switching delete="delete"/>', $payload);
        $this->assertStringContainsString('<address><name>10.9.9.7/24</name></address>', $payload);
    }

    /**
     * Maske sorulmaz: Adresleme'deki bloktan gelir. Blok yoksa adres bu
     * switche tanımlanamaz — IP adresleri ekranıyla AYNI kural, aynı kaynak.
     */
    public function test_bloksuz_adres_port_ekranindan_da_reddedilir(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, PortMode::Access);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Routed->value,
                'enabled' => '1',
                'ip_address' => '203.0.113.7',
            ])
            ->assertSessionHasErrors('ip_address');

        $this->assertNull($device->connection);
    }

    /**
     * Bir portta BİRDEN ÇOK adres olabilir ve düzenleme onları silmemeli.
     *
     * Omurgalarda olağan biçim — tek arayüzde iki IPv4 ve bir IPv6:
     *
     *     ge-0/0/6 unit 0 family inet address 198.51.100.129/30
     *     ge-0/0/6 unit 0 family inet address 198.18.5.109/30
     *     ge-0/0/6 unit 0 family inet6 address 2001:db8:0:120::1/64
     *
     * Panel adresi merge ile yazar: yazılmayan adreslere ve inet6 ailesine
     * hiç dokunulmaz. Belgede bir "address delete" ya da "inet replace"
     * çıksaydı, portun açıklamasını değiştirmek müşterinin ikinci IP'sini
     * ve IPv6'sını düşürürdü.
     */
    public function test_porttaki_diger_adresler_silinmez(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, PortMode::Routed);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Routed->value,
                'enabled' => '1',
                'description' => 'Musteri-A',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = $device->payload();

        $this->assertStringContainsString('<description>Musteri-A</description>', $yazilan);
        $this->assertStringNotContainsString('<address delete="delete"', $yazilan);
        $this->assertStringNotContainsString('<inet replace="replace"', $yazilan);
        $this->assertStringNotContainsString('<inet6', $yazilan);
    }

    /**
     * Aynı adres cihazda başka bir arayüzde duruyorsa reddedilir.
     */
    public function test_baska_arayuzdeki_adres_verilemez(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, PortMode::Access);

        AddressBlock::factory()->network('10.9.9.0/24')->create(['core_switch_id' => $switch->id]);

        SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'interface_name' => 'vlan.100',
            'address' => '10.9.9.7',
            'prefix_length' => 24,
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Routed->value,
                'enabled' => '1',
                'ip_address' => '10.9.9.7',
            ])
            ->assertSessionHasErrors('ip_address');

        $this->assertNull($device->connection);
    }

    /**
     * Anahtarlama modunda adres alanı hiç gönderilemez.
     */
    public function test_access_portta_adres_gonderilemez(): void
    {
        $switch = $this->switch();
        $port = $this->port($switch, PortMode::Access);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Access->value,
                'enabled' => '1',
                'ip_address' => '10.9.9.7',
            ])
            ->assertSessionHasErrors('ip_address');

        $this->assertNull($device->connection);
    }

    private function port(NetworkSwitch $switch, PortMode $mode): SwitchPort
    {
        return SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'if_index' => 505,
            'port_mode' => $mode,
            'is_poe_enabled' => null,
        ]);
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice('[edit interfaces]');
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }
}
