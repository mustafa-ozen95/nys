<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\PanelMode;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * lldp, lldp-med ve igmp-snooping.
 *
 * Erişim switchlerinde üçü birlikte yaygın olarak tanımlıdır:
 *
 *   set protocols lldp interface all
 *   set protocols lldp-med interface all
 *   set protocols igmp-snooping vlan all
 *
 * Panel onları göstermiyordu; cihazda duran ama panelde olmayan her satır
 * "panelden yönetiyoruz" iddiasını zayıflatır.
 *
 * İki lehçede de kabul edildiği ölçüldü (test2 ve test4); lehçe dallanması
 * gerekmiyor. "dcbx" modellenmedi.
 */
final class ProtokolAnahtarlariTest extends TestCase
{
    use RefreshDatabase;

    public function test_protokol_kapsami_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.device-settings', $switch), [
                'hostname' => $switch->hostname,
                'lldp_enabled' => '1',
                'lldp_scope' => 'all',
                'igmp_snooping_enabled' => '1',
                'igmp_snooping_scope' => "V10\nV20",
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString(
            '<lldp replace="replace"><interface><name>all</name></interface></lldp>',
            $payload,
        );
        $this->assertStringContainsString(
            '<igmp-snooping replace="replace"><vlan><name>V10</name></vlan><vlan><name>V20</name></vlan></igmp-snooping>',
            $payload,
        );
    }

    /**
     * KAPATMAK SİLER: boş bir düğüm bırakmak, protokolü kapsamsız ama
     * TANIMLI hâlde bırakırdı ve o farklı bir durumdur.
     */
    public function test_kapatmak_protokolu_siler(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['lldp_interfaces' => ['all']])->save();

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.device-settings', $switch), [
                'hostname' => $switch->hostname,
                'lldp_enabled' => '0',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<lldp delete="delete"/>', $device->payload());
    }

    /**
     * Açık ama kapsam verilmemişse cihazdaki yaygın kullanım "all".
     */
    public function test_kapsamsiz_acik_protokol_all_yazar(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.device-settings', $switch), [
                'hostname' => $switch->hostname,
                'lldp_med_enabled' => '1',
                'lldp_med_scope' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<lldp-med replace="replace"><interface><name>all</name></interface></lldp-med>',
            $device->payload(),
        );
    }

    /**
     * Kapsam DEĞİŞİRKEN önce silinir: Junos merge yalnızca ekler, bir
     * arayüzü listeden çıkarmak "replace" ister.
     */
    public function test_kapsam_degisiminde_replace_kullanilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['lldp_interfaces' => ['ge-0/0/1', 'ge-0/0/2']])->save();

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.device-settings', $switch), [
                'hostname' => $switch->hostname,
                'lldp_enabled' => '1',
                'lldp_scope' => 'ge-0/0/1',
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<lldp replace="replace">', $payload);
        $this->assertStringNotContainsString('ge-0/0/2', $payload);
    }

    public function test_ekranda_gorunur(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['igmp_snooping_vlans' => ['all']])->save();

        $this->actingAs($this->operator())
            ->get(route('switches.settings', $switch))
            ->assertOk()
            ->assertSee('IGMP snooping')
            ->assertSee('LLDP-MED');
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice('[edit protocols]');
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
        return User::factory()->admin()->create(['panel_mode' => PanelMode::Advanced])->fresh();
    }
}
