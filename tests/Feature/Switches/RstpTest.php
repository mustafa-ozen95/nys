<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\PortMode;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\SwitchRstpInterface;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Spanning tree.
 *
 * Panelin en geniş etkili L2 yazmasıdır: köprü önceliği kök seçimini
 * değiştirir, "edge" tek bir portta döngü yaratabilir. Kısıtların hepsi
 * jn32-test (12.3R12.4) üzerinde doğrulandı.
 */
final class RstpTest extends TestCase
{
    use RefreshDatabase;

    public function test_kopru_ayarlari_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.rstp.bridge', $switch), [
                'enabled' => '1',
                'bridge_priority' => '8k',
                'bpdu_block_on_edge' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = $device->payload();

        $this->assertStringContainsString('<bridge-priority>8k</bridge-priority>', $yazilan);
        $this->assertStringContainsString('<bpdu-block-on-edge/>', $yazilan);
        // Açıkken "disable" satırı SİLİNİR; yoksa kapalı kalırdı.
        $this->assertStringContainsString('<disable delete="delete"/>', $yazilan);
    }

    /**
     * "Kapalı" ile "hiç yazılmamış" aynı şey değildir: ilki açıkça kapatır.
     */
    public function test_kapatmak_disable_yazar(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.rstp.bridge', $switch), ['enabled' => '0'])
            ->assertRedirect();

        $this->assertStringContainsString('<disable/>', $device->payload());
    }

    /**
     * Junos köprü önceliğinde serbest sayı kabul etmez; 4096'nın katları.
     */
    public function test_gecersiz_kopru_onceligi_reddedilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.rstp.bridge', $switch), ['enabled' => '1', 'bridge_priority' => '5000'])
            ->assertSessionHasErrors('bridge_priority');

        $this->assertNull($device->connection);
    }

    public function test_arayuz_ayari_once_silinip_sonra_yazilir(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5', PortMode::Access);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.rstp.store', $switch), [
                'interface_name' => 'ge-0/0/5',
                'edge' => '1',
                'cost' => '20000',
                'priority' => '128',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = $device->payload();

        $this->assertStringContainsString('<edge delete="delete"/>', $yazilan);
        $this->assertStringContainsString('<edge/>', $yazilan);
        $this->assertStringContainsString('<cost>20000</cost>', $yazilan);
        $this->assertStringContainsString('<priority>128</priority>', $yazilan);
    }

    /**
     * Cihaz: "XSTP : Interface ge-0/0/23.0 is not enabled for Ethernet
     * Switching". Routed ve yapılandırılmamış portlarda spanning tree
     * tanımlanamaz; kısıt panelde de durmalı.
     */
    public function test_anahtarlama_disi_portta_ayar_verilemez(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/23', PortMode::Routed);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.rstp.store', $switch), [
                'interface_name' => 'ge-0/0/23',
                'edge' => '1',
            ])
            ->assertSessionHasErrors('interface_name');

        $this->assertNull($device->connection);
    }

    public function test_port_onceligi_onaltinin_kati_olmali(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5', PortMode::Access);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.rstp.store', $switch), [
                'interface_name' => 'ge-0/0/5',
                'priority' => '100',
            ])
            ->assertSessionHasErrors('priority');

        $this->assertNull($device->connection);
    }

    /**
     * "all" Junos'un kabul ettiği sanal addır ve portlar arasında karşılığı
     * yoktur; yine de seçilebilmeli.
     */
    public function test_butun_portlar_secilebilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.rstp.store', $switch), ['interface_name' => 'all', 'no_root_port' => '1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<name>all</name>', $device->payload());
    }

    public function test_okuma_yetkisi_yazmaya_yetmez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        $this->actingAs($user->fresh())
            ->put(route('switches.rstp.bridge', $switch), ['enabled' => '0'])
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    public function test_baska_switchin_arayuzu_uzerinden_islem_yapilamaz(): void
    {
        $switch = $this->switch();
        $digeri = $this->switch('198.18.20.196');
        $yabanci = SwitchRstpInterface::factory()->for($digeri, 'switch')->create(['interface_name' => 'ge-0/0/1']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->delete(route('switches.rstp.destroy', [$switch, $yabanci]))
            ->assertNotFound();

        $this->assertNull($device->connection);
    }

    private function port(NetworkSwitch $switch, string $name, PortMode $mode): SwitchPort
    {
        return SwitchPort::factory()->for($switch, 'switch')->create(['name' => $name, 'port_mode' => $mode]);
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }

    private function switch(string $ip = '198.18.20.195'): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => $ip]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
