<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Enums\LacpMode;
use App\Enums\PortType;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Bağlantı toplama (LAG).
 *
 * Kısıtların hepsi jn32-test üzerinde ölçüldü; beklenen XML de oradan alındı.
 */
final class LinkAggregationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * "ae limiti" bir üst sınır değil, kaç arayüzün yaratılacağıdır.
     */
    public function test_ae_limiti_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.device-count', $switch), ['device_count' => 4])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<aggregated-devices><ethernet><device-count>4</device-count>',
            $device->payload(),
        );
    }

    /**
     * Aralığı cihaz söylüyor: "Value 112 is not within range (1..111)".
     */
    public function test_aralik_disindaki_limit_reddedilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.device-count', $switch), ['device_count' => 112])
            ->assertSessionHasErrors('device_count');

        $this->assertNull($device->connection);
    }

    /**
     * Limiti düşürmek üstteki ae'yi cihazdan SESSİZCE siler; cihaz commit'te
     * uyarmıyor, o yüzden koruma panelde.
     */
    public function test_kullanilan_ae_yi_yok_edecek_limit_reddedilir(): void
    {
        $switch = $this->switch();
        $this->aggregate($switch, 'ae3', ['lacp_mode' => LacpMode::Active]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.device-count', $switch), ['device_count' => 2])
            ->assertSessionHasErrors('device_count');

        $this->assertNull($device->connection);
    }

    public function test_bos_ae_yi_dusuren_limit_kabul_edilir(): void
    {
        $switch = $this->switch();
        // Hiç yapılandırılmamış ae; kaybolması zararsız. Fabrika varsayılan
        // olarak açıklama koyuyor, o yüzden açıkça boşaltılıyor.
        $this->aggregate($switch, 'ae3', ['description' => null]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.device-count', $switch), ['device_count' => 2])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull($device->connection);
    }

    /**
     * Üye olacak portun kendi birimi AYNI commit'te silinmeli; yoksa cihaz
     * "logical unit is not allowed on aggregated links" der.
     */
    public function test_uye_portun_birimi_ayni_committe_silinir(): void
    {
        $switch = $this->switch();
        $ae = $this->aggregate($switch, 'ae0');
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/20']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.update', [$switch, $ae]), [
                'port_mode' => 'trunk',
                'members' => [$port->uuid],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = $device->payload();

        $this->assertStringContainsString('<unit delete="delete"><name>0</name></unit>', $yazilan);
        // Junos eleman adı "802.3ad" değil "ieee-802.3ad".
        $this->assertStringContainsString('<ieee-802.3ad><bundle>ae0</bundle></ieee-802.3ad>', $yazilan);
    }

    public function test_lacp_ve_toplama_ayarlari_yazilir(): void
    {
        $switch = $this->switch();
        $ae = $this->aggregate($switch, 'ae0');
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/20']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.update', [$switch, $ae]), [
                'port_mode' => 'trunk',
                'members' => [$port->uuid],
                'lacp_mode' => 'active',
                'lacp_periodic' => 'fast',
                'minimum_links' => 1,
                'link_speed' => '1g',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = $device->payload();

        // Ayarlar önce silinir: merge kaldırılan bir ayarı yerinde bırakırdı.
        $this->assertStringContainsString('<aggregated-ether-options delete="delete"/>', $yazilan);
        $this->assertStringContainsString('<lacp><active/><periodic>fast</periodic></lacp>', $yazilan);
        $this->assertStringContainsString('<minimum-links>1</minimum-links>', $yazilan);
        $this->assertStringContainsString('<link-speed>1g</link-speed>', $yazilan);
    }

    /**
     * Cihaz: "minimum-links is greater than the num of links configured".
     */
    public function test_uye_sayisini_gecen_en_az_baglanti_reddedilir(): void
    {
        $switch = $this->switch();
        $ae = $this->aggregate($switch, 'ae0');
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/20']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.update', [$switch, $ae]), [
                'port_mode' => 'trunk',
                'members' => [$port->uuid],
                'minimum_links' => 2,
            ])
            ->assertSessionHasErrors('minimum_links');

        $this->assertNull($device->connection);
    }

    public function test_uyesiz_lagda_en_az_baglanti_verilemez(): void
    {
        $switch = $this->switch();
        $ae = $this->aggregate($switch, 'ae0');
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.update', [$switch, $ae]), [
                'port_mode' => 'trunk',
                'minimum_links' => 1,
            ])
            ->assertSessionHasErrors('minimum_links');

        $this->assertNull($device->connection);
    }

    /**
     * Cihaz: "group limit of 8 exceeded".
     */
    public function test_sekizden_fazla_uye_reddedilir(): void
    {
        $switch = $this->switch();
        $ae = $this->aggregate($switch, 'ae0');
        $device = $this->device();

        $uyeler = collect(range(0, 8))
            ->map(fn (int $i): string => SwitchPort::factory()->for($switch, 'switch')
                ->create(['name' => "ge-0/0/{$i}"])->uuid)
            ->all();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.update', [$switch, $ae]), [
                'port_mode' => 'trunk',
                'members' => $uyeler,
            ])
            ->assertSessionHasErrors('members');

        $this->assertNull($device->connection);
    }

    /**
     * Bir port yalnızca tek bir ae'ye üye olabilir.
     */
    public function test_baska_ae_uyesi_port_secilemez(): void
    {
        $switch = $this->switch();
        $ae0 = $this->aggregate($switch, 'ae0');
        $ae1 = $this->aggregate($switch, 'ae1');
        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/20', 'aggregate_port_id' => $ae0->id]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.update', [$switch, $ae1]), [
                'port_mode' => 'trunk',
                'members' => [$port->uuid],
            ])
            ->assertSessionHasErrors('members');

        $this->assertNull($device->connection);
    }

    public function test_lacp_secilmeden_periyot_verilemez(): void
    {
        $switch = $this->switch();
        $ae = $this->aggregate($switch, 'ae0');
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.update', [$switch, $ae]), [
                'port_mode' => 'trunk',
                'lacp_periodic' => 'fast',
            ])
            ->assertSessionHasErrors('lacp_periodic');

        $this->assertNull($device->connection);
    }

    /**
     * ae silinirken üyelikler de kaldırılır: cihaz dangling üyeliği kabul
     * ediyor ama o port var olmayan bir ae'ye bağlı kalırdı.
     */
    public function test_ae_silinirken_uyelikler_de_kaldirilir(): void
    {
        $switch = $this->switch();
        $ae = $this->aggregate($switch, 'ae0', ['lacp_mode' => LacpMode::Active]);
        SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/20', 'aggregate_port_id' => $ae->id]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->delete(route('switches.aggregation.destroy', [$switch, $ae]))
            ->assertRedirect();

        $yazilan = $device->payload();

        $this->assertStringContainsString('<ieee-802.3ad delete="delete"/>', $yazilan);
        $this->assertStringContainsString('<interface delete="delete"><name>ae0</name></interface>', $yazilan);
    }

    /**
     * İç içe rotada alt kayıt üst kayda ait olmalı; ayrıca fiziksel bir port
     * ae uçlarında hiç eşleşmemeli.
     */
    public function test_fiziksel_port_ae_ucunda_bulunamaz(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/5']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.update', [$switch, $port]), ['port_mode' => 'trunk'])
            ->assertNotFound();

        $this->assertNull($device->connection);
    }

    public function test_baska_switchin_ae_si_kullanilamaz(): void
    {
        $switch = $this->switch();
        $digeri = $this->switch('198.18.20.196');
        $yabanci = $this->aggregate($digeri, 'ae0');
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.update', [$switch, $yabanci]), ['port_mode' => 'trunk'])
            ->assertNotFound();

        $this->assertNull($device->connection);
    }

    /**
     * ae'nin VLAN üyeliği toplama ayarlarıyla AYNI commit'te yazılır.
     */
    public function test_vlan_uyeligi_ayni_committe_yazilir(): void
    {
        $switch = $this->switch();
        $ae = $this->aggregate($switch, 'ae0');
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 20, 'name' => 'V20']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.aggregation.update', [$switch, $ae]), [
                'port_mode' => 'trunk',
                'vlans' => [$vlan->uuid],
                'untagged_vlan' => $vlan->uuid,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = $device->payload();

        $this->assertStringContainsString('<members>V20</members>', $yazilan);
        // Trunk'ta etiketsiz VLAN arayüz seviyesinde işaretlenir.
        $this->assertStringContainsString('<native-vlan-id>20</native-vlan-id>', $yazilan);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function aggregate(NetworkSwitch $switch, string $name, array $attributes = []): SwitchPort
    {
        return SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => $name,
            'interface_kind' => PortType::Aggregated,
            'is_poe_enabled' => null,
        ] + $attributes);
    }

    private function switch(string $ip = '198.18.20.195'): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'management_ip' => $ip,
            'aggregated_device_count' => 4,
        ]);

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

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
