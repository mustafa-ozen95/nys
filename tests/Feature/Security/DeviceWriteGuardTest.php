<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\CredentialProtocol;
use App\Enums\PortType;
use App\Models\BgpGroup;
use App\Models\BgpNeighbor;
use App\Models\NetworkSwitch;
use App\Models\SwitchCommunity;
use App\Models\SwitchFirewallFilter;
use App\Models\SwitchFirewallTerm;
use App\Models\SwitchIpAddress;
use App\Models\SwitchPolicyStatement;
use App\Models\SwitchPolicyTerm;
use App\Models\SwitchPort;
use App\Models\SwitchPrefixList;
use App\Models\SwitchPrefixListEntry;
use App\Models\SwitchRoute;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Cihaza yazan her uç sunucuda korunur.
 *
 * Arayüzde düğmeyi gizlemek yetmez: istek doğrudan da gelebilir. Bu test
 * her yazma ucunu "görüntüle + düzenle" yetkisiyle dener; hiçbiri geçmemeli,
 * çünkü cihaza yazmak ayrı bir yetkidir (configure).
 */
final class DeviceWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function writeEndpoints(): array
    {
        return [
            'port ayarları' => ['port'],
            'route ekleme' => ['route-store'],
            'route silme' => ['route-destroy'],
            'vlan ekleme' => ['vlan-store'],
            'vlan silme' => ['vlan-destroy'],
            'ip ekleme' => ['ip-store'],
            'ip silme' => ['ip-destroy'],
            'prefix list ekleme' => ['prefix-store'],
            'prefix silme' => ['prefix-entry-destroy'],
            'snmp adı' => ['snmp'],
            'AS numarası' => ['bgp-as'],
            'bgp grubu ekleme' => ['bgp-group-store'],
            'bgp grubu silme' => ['bgp-group-destroy'],
            'bgp komşusu ekleme' => ['bgp-neighbor-store'],
            'bgp komşusu silme' => ['bgp-neighbor-destroy'],
            'politika ekleme' => ['policy-store'],
            'politika silme' => ['policy-destroy'],
            'terim ekleme' => ['policy-term-store'],
            'terim düzenleme' => ['policy-term-update'],
            'community ekleme' => ['community-store'],
            'community silme' => ['community-destroy'],
            'ae limiti' => ['ae-device-count'],
            'ae ayarları' => ['ae-update'],
            'ae silme' => ['ae-destroy'],
            // Firewall ayrıca YÖNETİCİ ister; configure yetkisi de yetmez.
            'firewall filtresi ekleme' => ['firewall-store'],
            'firewall filtresi silme' => ['firewall-destroy'],
            'firewall terimi ekleme' => ['firewall-term-store'],
            'firewall terimi düzenleme' => ['firewall-term-update'],
        ];
    }

    #[DataProvider('writeEndpoints')]
    public function test_yazma_uclari_configure_yetkisi_ister(string $endpoint): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();
        $user = $this->operator(['view', 'create', 'update', 'delete']);

        $request = $this->request($endpoint, $switch);

        $this->actingAs($user)
            ->call($request['method'], $request['url'], $request['data'])
            ->assertForbidden();

        // Yetki reddedildiyse cihaza hiç bağlanılmamalı.
        $this->assertNull($device->connection);
    }

    /**
     * @return array{method: string, url: string, data: array<string, mixed>}
     */
    private function request(string $endpoint, NetworkSwitch $switch): array
    {
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/5']);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30, 'name' => 'V30']);
        $route = $switch->routes()->create([
            'destination' => '10.9.9.0', 'prefix_length' => 24,
            'next_hop' => '10.0.0.1', 'protocol' => 'static', 'family' => 'inet',
        ]);
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create();
        $entry = SwitchPrefixListEntry::factory()->for($list, 'prefixList')->create();
        $address = SwitchIpAddress::factory()->for($switch, 'switch')
            ->create(['interface_name' => 'vlan.30', 'vlan_id' => $vlan->id]);
        $group = BgpGroup::factory()->for($switch, 'switch')->create();
        $neighbor = BgpNeighbor::factory()->for($group, 'group')->for($switch, 'switch')->create();
        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create();
        $policyTerm = SwitchPolicyTerm::factory()->for($policy, 'statement')->create();
        $community = SwitchCommunity::factory()->for($switch, 'switch')->create();
        $aggregate = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ae0', 'interface_kind' => PortType::Aggregated, 'is_poe_enabled' => null]);
        $firewall = SwitchFirewallFilter::factory()->for($switch, 'switch')->create();
        $firewallTerm = SwitchFirewallTerm::factory()->for($firewall, 'filter')->create();

        return match ($endpoint) {
            'port' => ['method' => 'PUT', 'url' => route('switches.ports.update', [$switch, $port]), 'data' => ['port_mode' => 'access']],
            'route-store' => ['method' => 'POST', 'url' => route('switches.routes.store', $switch), 'data' => ['destination' => '10.8.8.0', 'prefix_length' => 24]],
            'route-destroy' => ['method' => 'DELETE', 'url' => route('switches.routes.destroy', [$switch, $route]), 'data' => []],
            'vlan-store' => ['method' => 'POST', 'url' => route('switches.vlans.store', $switch), 'data' => ['name' => 'kamera', 'vlan_tag' => 31]],
            'vlan-destroy' => ['method' => 'DELETE', 'url' => route('switches.vlans.destroy', [$switch, $vlan]), 'data' => []],
            'ip-store' => ['method' => 'POST', 'url' => route('switches.ip-addresses.store', $switch), 'data' => ['attachment' => 'vlan', 'address' => '198.18.21.1', 'vlan_id' => $vlan->uuid]],
            'ip-destroy' => ['method' => 'DELETE', 'url' => route('switches.ip-addresses.destroy', [$switch, $address]), 'data' => []],
            'prefix-store' => ['method' => 'POST', 'url' => route('switches.prefix-lists.store', $switch), 'data' => ['name' => 'YENI']],
            'prefix-entry-destroy' => ['method' => 'DELETE', 'url' => route('switches.prefix-lists.entries.destroy', [$switch, $list, $entry]), 'data' => []],
            'snmp' => ['method' => 'PUT', 'url' => route('switches.snmp-identity', $switch), 'data' => ['name' => 'test1']],
            'bgp-as' => ['method' => 'PUT', 'url' => route('switches.bgp.autonomous-system', $switch), 'data' => ['autonomous_system' => '65000']],
            'bgp-group-store' => ['method' => 'POST', 'url' => route('switches.bgp.groups.store', $switch), 'data' => ['name' => 'yenigrup', 'type' => 'external', 'peer_asn' => '65001']],
            'bgp-group-destroy' => ['method' => 'DELETE', 'url' => route('switches.bgp.groups.destroy', [$switch, $group]), 'data' => []],
            'bgp-neighbor-store' => ['method' => 'POST', 'url' => route('switches.bgp.neighbors.store', [$switch, $group]), 'data' => ['address' => '10.7.7.7']],
            'bgp-neighbor-destroy' => ['method' => 'DELETE', 'url' => route('switches.bgp.neighbors.destroy', [$switch, $group, $neighbor]), 'data' => []],
            'policy-store' => ['method' => 'POST', 'url' => route('switches.policies.store', $switch), 'data' => ['statement_name' => 'yeni', 'name' => 'A', 'action' => 'accept']],
            'policy-destroy' => ['method' => 'DELETE', 'url' => route('switches.policies.destroy', [$switch, $policy]), 'data' => []],
            'policy-term-store' => ['method' => 'POST', 'url' => route('switches.policies.terms.store', [$switch, $policy]), 'data' => ['name' => 'B', 'action' => 'reject']],
            'policy-term-update' => ['method' => 'PUT', 'url' => route('switches.policies.terms.update', [$switch, $policy, $policyTerm]), 'data' => ['name' => 'B', 'action' => 'reject']],
            'community-store' => ['method' => 'POST', 'url' => route('switches.communities.store', $switch), 'data' => ['name' => 'topluluk-a', 'members' => '65000:666']],
            'community-destroy' => ['method' => 'DELETE', 'url' => route('switches.communities.destroy', [$switch, $community]), 'data' => []],
            'ae-device-count' => ['method' => 'PUT', 'url' => route('switches.aggregation.device-count', $switch), 'data' => ['device_count' => 4]],
            'ae-update' => ['method' => 'PUT', 'url' => route('switches.aggregation.update', [$switch, $aggregate]), 'data' => ['port_mode' => 'trunk']],
            'ae-destroy' => ['method' => 'DELETE', 'url' => route('switches.aggregation.destroy', [$switch, $aggregate]), 'data' => []],
            'firewall-store' => ['method' => 'POST', 'url' => route('switches.firewall.store', $switch), 'data' => ['filter_name' => 'yeni', 'family' => 'inet', 'name' => 't', 'action' => 'accept']],
            'firewall-destroy' => ['method' => 'DELETE', 'url' => route('switches.firewall.destroy', [$switch, $firewall]), 'data' => []],
            'firewall-term-store' => ['method' => 'POST', 'url' => route('switches.firewall.terms.store', [$switch, $firewall]), 'data' => ['name' => 't2', 'action' => 'discard']],
            'firewall-term-update' => ['method' => 'PUT', 'url' => route('switches.firewall.terms.update', [$switch, $firewall, $firewallTerm]), 'data' => ['name' => 't2', 'action' => 'discard']],
        };
    }

    /**
     * NETCONF kimliği olmayan switch hiç yapılandırılamaz: yönetici bile
     * olsa "configure" verilmez, çünkü yazacak bir yol yoktur.
     */
    public function test_netconf_kimligi_olmayan_switche_yonetici_de_yazamaz(): void
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.196']);
        $port = SwitchPort::factory()->for($switch, 'switch')->create();
        $device = $this->fakeDevice();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('switches.ports.update', [$switch, $port]), ['port_mode' => 'access'])
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    /**
     * Öğrenilen route yapılandırmada yoktur; silinmeye çalışılırsa cihaz
     * reddeder, istek oraya kadar gitmemeli.
     */
    public function test_ogrenilen_route_silinemez(): void
    {
        $switch = $this->switch();
        $route = $switch->routes()->create([
            'destination' => '198.18.20.0', 'prefix_length' => 24,
            'next_hop' => 'me0.0', 'protocol' => 'direct', 'family' => 'inet',
        ]);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->delete(route('switches.routes.destroy', [$switch, $route]))
            ->assertForbidden();

        $this->assertNull($device->connection);
        $this->assertSame(1, SwitchRoute::count());
    }

    /**
     * Yönetim arayüzünün adresi değiştirilirse panelin cihaza erişimi kesilir.
     */
    public function test_yonetim_arayuzunun_adresi_degistirilemez(): void
    {
        $switch = $this->switch();
        $address = SwitchIpAddress::factory()->for($switch, 'switch')
            ->create(['interface_name' => 'me0.0', 'vlan_id' => null, 'switch_port_id' => null]);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->delete(route('switches.ip-addresses.destroy', [$switch, $address]))
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    /**
     * PoE donanımı olmayan porta PoE gönderilmesi sessizce yutulmaz.
     */
    public function test_poe_donanimi_olmayan_porta_poe_gonderilemez(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/12', 'is_poe_enabled' => null]);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30]);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'vlans' => [$vlan->uuid],
                'is_poe_enabled' => '1',
            ])
            ->assertSessionHasErrors('is_poe_enabled');

        $this->assertNull($device->connection);
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

    /**
     * @param  list<string>  $actions
     */
    private function operator(array $actions): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', $actions);

        /*
         * Cihaz nesnelerinin (port, VLAN, route...) kendi yetkileri var;
         * switch yetkisi onları kapsamıyor. "configure" cihazın kendi
         * ayarlarıdır ve testin niyeti "cihaza yazabilen kullanıcı"dır;
         * "update" ise envanter kaydını düzenlemektir ve cihaza yazdırmaz.
         */
        in_array('configure', $actions, true)
            ? $this->grantDeviceWrite($user)
            : $this->grantDeviceView($user);

        return $user->fresh();
    }

    private function fakeDevice(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
