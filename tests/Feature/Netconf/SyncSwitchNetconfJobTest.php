<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Enums\RouteProtocol;
use App\Jobs\SyncSwitchNetconfJob;
use App\Models\NetworkSwitch;
use App\Models\SwitchPrefixList;
use App\Models\SwitchRoute;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

final class SyncSwitchNetconfJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_ve_prefix_listleri_kaydeder(): void
    {
        $switch = $this->switch();
        $this->fakeDevice();

        SyncSwitchNetconfJob::dispatchSync($switch->id);

        $this->assertSame(5, $switch->routes()->count());
        $this->assertDatabaseHas('switch_routes', [
            'switch_id' => $switch->id,
            'destination' => '198.18.20.0',
            'prefix_length' => 24,
            'next_hop' => 'me0.0',
            'protocol' => RouteProtocol::Direct->value,
        ]);

        // Aktif tabloda olmayan ama yapılandırmada duran statik route da
        // kaydedilir; pasif işaretlenir. Yoksa panelden eklenen route
        // sonraki eşitlemede kaybolurdu.
        $this->assertDatabaseHas('switch_routes', [
            'switch_id' => $switch->id,
            'destination' => '0.0.0.0',
            'prefix_length' => 0,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('switch_routes', [
            'switch_id' => $switch->id,
            'destination' => '198.18.20.0',
            'is_active' => true,
        ]);

        // Donanım bilgisi ve commit geçmişi de aynı oturumda okunur.
        $this->assertSame('EX4200-24T', $switch->fresh()->model);
        $this->assertSame('12.3R12.4', $switch->fresh()->junos_version);
        $this->assertDatabaseHas('switch_commits', [
            'switch_id' => $switch->id,
            'sequence_number' => 0,
            'committed_by' => 'admin',
            'comment' => 'NMS - Ayse Yilmaz - route eklendi',
        ]);

        $this->assertDatabaseHas('switch_prefix_lists', ['switch_id' => $switch->id, 'name' => 'koru']);

        // Portlar da aynı oturumda okunur; iç arayüzler (me0) envantere girmez.
        // Alan ayrıştırmanın ayrıntısı PortReaderTest'te.
        $this->assertSame(2, $switch->ports()->count());
        $this->assertDatabaseHas('switch_ports', [
            'switch_id' => $switch->id,
            'name' => 'ge-0/0/0',
            'if_index' => 525,
            'admin_status' => 'up',
        ]);

        $this->assertSame(2, $switch->vlans()->count());
        $this->assertDatabaseHas('vlans', ['switch_id' => $switch->id, 'vlan_tag' => 100]);
        $this->assertSame(0, SwitchPrefixList::where('name', 'koru')->sole()->entries()->count());

        $this->assertNotNull($switch->fresh()->last_sync_at);
    }

    public function test_tekrar_calistirildiginda_kayit_cogaltmaz(): void
    {
        $switch = $this->switch();

        $this->fakeDevice();
        SyncSwitchNetconfJob::dispatchSync($switch->id);

        $this->fakeDevice();
        SyncSwitchNetconfJob::dispatchSync($switch->id);

        $this->assertSame(5, $switch->routes()->count());
        $this->assertSame(1, $switch->prefixLists()->count());
    }

    /**
     * Cihazdan düşen yol envanterden de düşer.
     */
    public function test_cihazda_kalmayan_yol_silinir(): void
    {
        $switch = $this->switch();
        $switch->routes()->create([
            'destination' => '192.168.1.0',
            'prefix_length' => 24,
            'next_hop' => '192.168.1.1',
            'protocol' => RouteProtocol::Static,
            'family' => 'inet',
        ]);

        $this->fakeDevice();
        SyncSwitchNetconfJob::dispatchSync($switch->id);

        $this->assertDatabaseMissing('switch_routes', ['destination' => '192.168.1.0']);
        $this->assertSame(5, $switch->routes()->count());
    }

    /**
     * Geçici bir erişim sorunu envanteri boşaltmamalı.
     */
    public function test_cihaz_yanit_vermezse_mevcut_kayitlar_silinmez(): void
    {
        $switch = $this->switch();
        $switch->routes()->create([
            'destination' => '192.168.1.0',
            'prefix_length' => 24,
            'next_hop' => '192.168.1.1',
            'protocol' => RouteProtocol::Static,
            'family' => 'inet',
        ]);

        // Hello bile gelmiyor: bağlantı koptu.
        // Hello bile gelmiyor: bağlantı koptu.
        $this->app->instance(NetconfTransport::class, new FakeNetconfTransport([]));

        SyncSwitchNetconfJob::dispatchSync($switch->id);

        $this->assertSame(1, SwitchRoute::count());
        $this->assertDatabaseHas('switch_routes', ['destination' => '192.168.1.0']);
    }

    public function test_kimligi_olmayan_switch_icin_baglanti_denenmez(): void
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.196']);
        $device = $this->fakeDevice();

        SyncSwitchNetconfJob::dispatchSync($switch->id);

        $this->assertNull($device->connection);
        $this->assertSame(0, SwitchRoute::count());
    }

    public function test_commit_degismediyse_envanter_yeniden_okunmaz(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        SyncSwitchNetconfJob::dispatchSync($switch->id);

        $ilkTur = $device->written;
        $this->assertTrue(
            collect($ilkTur)->contains(fn (string $rpc): bool => str_contains($rpc, 'get-interface-information')),
            'İlk turda envanter okunmalı.',
        );

        $device->written = [];
        SyncSwitchNetconfJob::dispatchSync($switch->id);

        /*
         * İkinci turda cihazın son commit'i değişmedi: arayüz, VLAN, route,
         * firewall ve BGP okumalarının hiçbiri yapılmamalı. Cihaza binen yük
         * on beş RPC'den bir RPC'ye iner.
         */
        $this->assertFalse(
            collect($device->written)->contains(fn (string $rpc): bool => str_contains($rpc, 'get-interface-information')),
            'Commit değişmediği hâlde envanter yeniden okundu.',
        );

        $this->assertLessThan(count($ilkTur), count($device->written));
    }

    public function test_degisim_algisi_kapaliyken_her_turda_okunur(): void
    {
        config(['netconf.change_detection' => false]);

        $switch = $this->switch();
        $device = $this->fakeDevice();

        SyncSwitchNetconfJob::dispatchSync($switch->id);
        $device->written = [];
        SyncSwitchNetconfJob::dispatchSync($switch->id);

        $this->assertTrue(
            collect($device->written)->contains(fn (string $rpc): bool => str_contains($rpc, 'get-interface-information')),
        );
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'hostname' => 'jn32-test',
            'management_ip' => '198.18.20.195',
            'last_sync_at' => null,
        ]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    private function fakeDevice(): FakeJunosDevice
    {
        $device = new FakeJunosDevice(
            interfaces: [
                ['name' => 'ge-0/0/0', 'admin' => 'up', 'oper' => 'up', 'index' => 525],
                ['name' => 'ge-0/0/1', 'admin' => 'up', 'oper' => 'down', 'index' => 526],
                ['name' => 'me0', 'admin' => 'up', 'oper' => 'up', 'index' => 1],
            ],
            vlans: ['V00' => 100, 'V01' => 101],
            routeInformation: $this->fixture('route-information'),
            staticConfig: $this->staticConfig(),
            policyOptions: $this->fixture('policy-options-empty'),
            commitInformation: $this->commitHistory(),
        );

        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function responses(): array
    {
        return [
            '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
                .'<capability>urn:ietf:params:netconf:base:1.0</capability></capabilities></hello>'."\n]]>]]>",
            $this->software(),
            $this->chassis(),
            $this->interfaceStatus(),
            $this->interfaceConfig(),
            $this->fixture('route-information'),
            $this->staticConfig(),
            $this->fixture('policy-options-empty'),
            $this->commitHistory(),
        ];
    }

    private function staticConfig(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration>'
            .'<routing-options><static>'
            .'<route><name>0.0.0.0/0</name><next-hop>198.18.8.37</next-hop></route>'
            .'</static></routing-options></configuration></rpc-reply>'."\n]]>]]>";
    }

    private function commitHistory(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" '
            .'xmlns:junos="http://xml.juniper.net/junos/12.3R12/junos"><commit-information>'
            .'<commit-history><sequence-number>0</sequence-number><user>admin</user><client>netconf</client>'
            .'<date-time junos:seconds="1577836800">2020-01-01 00:00:00 UTC</date-time>'
            .'<comment>commit confirmed, rollback in 2mins</comment>'
            .'<log>NMS - Ayse Yilmaz - route eklendi</log></commit-history>'
            .'</commit-information></rpc-reply>'."\n]]>]]>";
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/netconf/{$name}.xml"))."\n]]>]]>";
    }
}
