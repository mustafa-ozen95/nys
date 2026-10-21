<?php

declare(strict_types=1);

namespace Tests\Feature\Snmp;

use App\Enums\CredentialProtocol;
use App\Enums\PortMode;
use App\Jobs\SyncSwitchCommitsJob;
use App\Jobs\SyncSwitchIpAddressesJob;
use App\Jobs\SyncSwitchVlansJob;
use App\Models\NetworkSwitch;
use App\Models\SwitchCredential;
use App\Models\SwitchPort;
use App\Models\Vlan;
use App\Services\Snmp\CommitReader;
use App\Services\Snmp\IpAddressReader;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpException;
use App\Services\Snmp\VlanReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cihaza hiçbir testte bağlanılmaz: SnmpClient sahtelenir.
 *
 * Sabit değerler bir EX2200'ün SNMP yanıt yapısını taklit eder.
 */
final class SnmpSyncTest extends TestCase
{
    use RefreshDatabase;

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'hostname' => 'sw-test-1',
            'management_ip' => '198.18.30.39',
            'uptime_seconds' => 3_600_000,
        ]);

        SwitchCredential::factory()->for($switch, 'switch')->create([
            'protocol' => CredentialProtocol::SnmpV2c,
            'community' => 'gizli',
            'port' => 161,
        ]);

        return $switch->fresh();
    }

    /**
     * @param  array<string, array<string, string>>  $walks
     */
    private function fakeSnmp(array $walks): void
    {
        $this->mock(SnmpClient::class, function ($mock) use ($walks): void {
            $mock->shouldReceive('walk')->andReturnUsing(
                fn ($switch, string $oid): array => $walks[$oid] ?? []
            );
        });
    }

    /**
     * EX2200'ün yapısı: vlan idx 2 -> bridge port 514 -> mantıksal 504
     * -> fiziksel 503 -> ge-0/0/1
     *
     * @return array<string, array<string, string>>
     */
    private function kabinWalks(): array
    {
        $oids = config('snmp.oids');

        return [
            $oids['vlan_tag'] => ['2' => '1', '3' => '10'],
            $oids['vlan_name'] => ['2' => 'V1', '3' => 'V10'],
            $oids['vlan_l3_interface'] => ['2' => 'vlan.0', '3' => 'vlan.9'],
            $oids['vlan_port_tagness'] => ['2.514' => '2'],
            $oids['bridge_port_ifindex'] => ['514' => '504'],
            $oids['if_stack'] => ['504.503' => '1', '0.503' => '2'],
            $oids['port_pvid'] => ['514' => '1'],
        ];
    }

    public function test_it_stores_vlans_with_tag_name_and_l3_interface(): void
    {
        $switch = $this->switch();
        $this->fakeSnmp($this->kabinWalks());

        (new SyncSwitchVlansJob($switch->id))->handle(app(VlanReader::class));

        $this->assertDatabaseHas('vlans', [
            'switch_id' => $switch->id, 'vlan_tag' => 1, 'name' => 'V1', 'l3_interface' => 'vlan.0',
        ]);
        $this->assertDatabaseHas('vlans', ['vlan_tag' => 10, 'name' => 'V10', 'l3_interface' => 'vlan.9']);
    }

    public function test_the_logical_interface_is_translated_to_the_physical_port(): void
    {
        $switch = $this->switch();
        // Panel fiziksel portu tutar: ifIndex 503, mantıksal 504 değil.
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/1', 'if_index' => 503]);

        $this->fakeSnmp($this->kabinWalks());
        (new SyncSwitchVlansJob($switch->id))->handle(app(VlanReader::class));

        $uyelik = $port->fresh()->vlans()->first();

        $this->assertNotNull($uyelik, 'VLAN üyeliği fiziksel porta bağlanmadı');
        $this->assertSame(1, $uyelik->vlan_tag);
        $this->assertTrue((bool) $uyelik->pivot->is_untagged);
        $this->assertSame(PortMode::Access, $port->fresh()->port_mode);
    }

    public function test_a_port_with_several_vlans_is_a_trunk(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['if_index' => 503]);

        $walks = $this->kabinWalks();
        // Aynı port iki VLAN'da, biri etiketli.
        $walks[config('snmp.oids.vlan_port_tagness')] = ['2.514' => '2', '3.514' => '1'];

        $this->fakeSnmp($walks);
        (new SyncSwitchVlansJob($switch->id))->handle(app(VlanReader::class));

        $this->assertCount(2, $port->fresh()->vlans);
        $this->assertSame(PortMode::Trunk, $port->fresh()->port_mode);
    }

    public function test_a_vlan_that_disappeared_from_the_device_is_removed(): void
    {
        $switch = $this->switch();
        Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 999, 'name' => 'ESKI']);

        $this->fakeSnmp($this->kabinWalks());
        (new SyncSwitchVlansJob($switch->id))->handle(app(VlanReader::class));

        $this->assertDatabaseMissing('vlans', ['vlan_tag' => 999]);
    }

    public function test_an_unreachable_device_does_not_wipe_the_inventory(): void
    {
        $switch = $this->switch();
        Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30]);

        $this->mock(SnmpClient::class, function ($mock): void {
            $mock->shouldReceive('walk')->andThrow(new SnmpException('zaman aşımı'));
        });

        (new SyncSwitchVlansJob($switch->id))->handle(app(VlanReader::class));

        // Geçici erişim sorunu envanteri boşaltmamalı.
        $this->assertDatabaseHas('vlans', ['vlan_tag' => 30]);
    }

    public function test_a_switch_without_an_snmp_credential_is_skipped(): void
    {
        $switch = NetworkSwitch::factory()->create();

        $this->mock(SnmpClient::class, function ($mock): void {
            $mock->shouldNotReceive('walk');
        });

        (new SyncSwitchVlansJob($switch->id))->handle(app(VlanReader::class));

        $this->assertDatabaseCount('vlans', 0);
    }

    public function test_ip_addresses_are_stored_with_the_right_prefix_and_port(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/1', 'if_index' => 623]);
        $oids = config('snmp.oids');

        $this->fakeSnmp([
            $oids['ip_ifindex'] => ['198.18.30.39' => '34', '198.18.1.57' => '623'],
            $oids['ip_netmask'] => ['198.18.30.39' => '255.255.255.0', '198.18.1.57' => '255.255.255.248'],
            $oids['if_name'] => ['34' => 'me0.0', '623' => 'vlan.25'],
        ]);

        (new SyncSwitchIpAddressesJob($switch->id))->handle(app(IpAddressReader::class));

        // Adresler çoğunlukla L3 arayüzündedir; adı cihazdan okunur.
        $this->assertDatabaseHas('switch_ip_addresses', [
            'address' => '198.18.30.39', 'prefix_length' => 24, 'interface_name' => 'me0.0',
        ]);
        $this->assertDatabaseHas('switch_ip_addresses', [
            'address' => '198.18.1.57', 'prefix_length' => 29, 'switch_port_id' => $port->id,
        ]);
    }

    public function test_commit_history_is_stored_with_the_user(): void
    {
        $switch = $this->switch();
        $oids = config('snmp.oids');

        $this->fakeSnmp([
            // İstemci "-Ot" ile okur: timetick ham sayıdır.
            $oids['commit_time'] => ['96' => '3597755992'],
            $oids['commit_user'] => ['96' => 'admin'],
        ]);

        (new SyncSwitchCommitsJob($switch->id))->handle(app(CommitReader::class));

        $this->assertDatabaseHas('switch_commits', [
            'switch_id' => $switch->id, 'sequence_number' => 96, 'committed_by' => 'admin',
        ]);
        $this->assertNotNull($switch->commits()->first()->committed_at);
    }

    public function test_the_unique_lock_expires_so_a_lost_job_cannot_block_the_next_run(): void
    {
        // uniqueFor olmadan kilit iş bitene kadar durur; iş kaybolursa
        // sonraki her dispatch sessizce atılır.
        foreach ([SyncSwitchVlansJob::class, SyncSwitchIpAddressesJob::class, SyncSwitchCommitsJob::class] as $job) {
            $this->assertGreaterThan(
                0,
                (new $job(1))->uniqueFor,
                "{$job} icin uniqueFor tanimli degil",
            );
        }
    }

    public function test_a_formatted_timetick_leaves_the_date_empty_instead_of_guessing(): void
    {
        $switch = $this->switch();
        $oids = config('snmp.oids');

        // "-Ot" olmadan NET-SNMP "416:9:45:59.92" biçiminde yazar. Bundan
        // tarih uydurmak yerine boş bırakılır.
        $this->fakeSnmp([
            $oids['commit_time'] => ['5' => '416:9:45:59.92'],
            $oids['commit_user'] => ['5' => 'admin'],
        ]);

        (new SyncSwitchCommitsJob($switch->id))->handle(app(CommitReader::class));

        $commit = $switch->commits()->firstWhere('sequence_number', 5);

        $this->assertSame('admin', $commit->committed_by);
        $this->assertNull($commit->committed_at);
    }
}
