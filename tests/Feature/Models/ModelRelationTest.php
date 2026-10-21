<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\CredentialProtocol;
use App\Enums\IpFamily;
use App\Enums\PortMode;
use App\Enums\SwitchStatus;
use App\Models\ActivityLog;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\Server;
use App\Models\SwitchCommit;
use App\Models\SwitchCredential;
use App\Models\SwitchIpAddress;
use App\Models\SwitchPort;
use App\Models\SwitchPrefixList;
use App\Models\SwitchPrefixListEntry;
use App\Models\SwitchRoute;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\Vlan;
use App\Support\Net\Cidr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ModelRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_exposes_all_of_its_relations(): void
    {
        $switch = NetworkSwitch::factory()->create();

        SwitchCredential::factory()->for($switch, 'switch')->create();
        SwitchCredential::factory()->for($switch, 'switch')->snmp()->create();
        SwitchPort::factory()->for($switch, 'switch')->create();
        Vlan::factory()->for($switch, 'switch')->create();
        SwitchIpAddress::factory()->for($switch, 'switch')->create();
        SwitchRoute::factory()->for($switch, 'switch')->create();
        SwitchPrefixList::factory()->for($switch, 'switch')->create();
        SwitchCommit::factory()->for($switch, 'switch')->create();

        $switch->refresh();

        $this->assertCount(2, $switch->credentials);
        $this->assertSame(CredentialProtocol::Ssh, $switch->sshCredential->protocol);
        $this->assertSame(CredentialProtocol::SnmpV2c, $switch->snmpCredential->protocol);
        $this->assertCount(1, $switch->ports);
        $this->assertCount(1, $switch->vlans);
        $this->assertCount(1, $switch->ipAddresses);
        $this->assertCount(1, $switch->routes);
        $this->assertCount(1, $switch->prefixLists);
        $this->assertCount(1, $switch->commits);
    }

    public function test_interfaces_and_vlans_share_a_pivot_with_tagging_information(): void
    {
        $switch = NetworkSwitch::factory()->create();
        $port = SwitchPort::factory()->for($switch, 'switch')->create();
        $vlan = Vlan::factory()->for($switch, 'switch')->create();

        $port->vlans()->attach($vlan, ['is_untagged' => true]);

        $this->assertTrue((bool) $port->vlans()->first()->pivot->is_untagged);
        $this->assertTrue($vlan->ports->contains($port));
    }

    public function test_prefix_list_owns_its_entries(): void
    {
        $list = SwitchPrefixList::factory()->create();
        SwitchPrefixListEntry::factory()->count(3)->for($list, 'prefixList')->create();

        $this->assertCount(3, $list->refresh()->entries);
        $this->assertSame($list->id, $list->entries->first()->prefixList->id);
    }

    public function test_address_blocks_nest_through_a_parent_relation(): void
    {
        $parent = AddressBlock::factory()->network('198.19.0.0/16')->create(['is_subnetted' => true]);
        $child = AddressBlock::factory()->network('198.19.1.0/24')->create(['parent_id' => $parent->id]);

        $this->assertCount(1, $parent->refresh()->subnets);
        $this->assertSame($parent->id, $child->parent->id);
        $this->assertSame('198.19.1.0/24', $child->cidr);
    }

    public function test_overlapping_scope_finds_intersecting_blocks_in_sql(): void
    {
        AddressBlock::factory()->network('10.40.0.0/16')->create();
        AddressBlock::factory()->network('10.50.0.0/16')->create();

        $target = Cidr::parse('10.40.5.0/24');

        $matches = AddressBlock::query()
            ->overlapping($target->addressStart(), $target->addressEnd(), IpFamily::Inet)
            ->pluck('network');

        $this->assertSame(['10.40.0.0'], $matches->all());
    }

    /**
     * Çakışma kontrolü AİLEYE bağlıdır: bir IPv4 bloğu bir IPv6 bloğuyla
     * çakışamaz ve paketlenmiş baytlarını karşılaştırmak anlamsız olurdu.
     */
    public function test_overlapping_scope_never_mixes_families(): void
    {
        AddressBlock::factory()->network('2001:db8::/32')->create();

        $target = Cidr::parse('2001:db8:1::/48');

        $this->assertSame(
            ['2001:db8::'],
            AddressBlock::query()
                ->overlapping($target->addressStart(), $target->addressEnd(), IpFamily::Inet6)
                ->pluck('network')
                ->all(),
        );

        $this->assertEmpty(
            AddressBlock::query()
                ->overlapping($target->addressStart(), $target->addressEnd(), IpFamily::Inet)
                ->pluck('network')
                ->all(),
        );
    }

    public function test_activity_log_points_at_any_subject(): void
    {
        $switch = NetworkSwitch::factory()->create();
        $log = ActivityLog::factory()->create([
            'subject_type' => $switch->getMorphClass(),
            'subject_id' => $switch->id,
        ]);

        $this->assertTrue($log->subject->is($switch));
        $this->assertTrue($log->user->activityLogs->contains($log));
    }

    public function test_user_owns_its_permissions(): void
    {
        $user = User::factory()->create();
        UserPermission::factory()->for($user)->create();
        UserPermission::factory()->for($user)->resource('servers', 'update')->create();

        $this->assertCount(2, $user->refresh()->permissions);
        $this->assertCount(2, $user->permissions()->wildcard()->get());
    }

    public function test_enum_columns_are_cast_in_both_directions(): void
    {
        $switch = NetworkSwitch::factory()->offline()->create();

        $this->assertSame(SwitchStatus::Offline, $switch->status);
        $this->assertSame('offline', DB::table('switches')->value('status'));

        $port = SwitchPort::factory()->trunk()->create();
        $this->assertSame(PortMode::Trunk, $port->port_mode);
        $this->assertSame('trunk', DB::table('switch_ports')->value('port_mode'));
    }

    public function test_secrets_are_stored_encrypted_and_read_back_in_clear(): void
    {
        $credential = SwitchCredential::factory()->create(['password' => 'gizli-parola']);
        $server = Server::factory()->create(['connection_password' => 'sunucu-parolasi']);

        $storedCredential = DB::table('switch_credentials')->value('password');
        $storedServer = DB::table('servers')->value('connection_password');

        $this->assertNotSame('gizli-parola', $storedCredential);
        $this->assertSame('gizli-parola', Crypt::decryptString($storedCredential));
        $this->assertSame('gizli-parola', $credential->fresh()->password);

        $this->assertNotSame('sunucu-parolasi', $storedServer);
        $this->assertSame('sunucu-parolasi', $server->fresh()->connection_password);
    }

    public function test_secrets_are_hidden_from_array_output(): void
    {
        $credential = SwitchCredential::factory()->create();

        $this->assertArrayNotHasKey('password', $credential->toArray());
        $this->assertArrayNotHasKey('community', $credential->toArray());
        $this->assertArrayNotHasKey('connection_password', Server::factory()->create()->toArray());
    }

    public function test_soft_deleted_switches_leave_the_default_query(): void
    {
        $switch = NetworkSwitch::factory()->create();
        $switch->delete();

        $this->assertSame(0, NetworkSwitch::query()->count());
        $this->assertSame(1, NetworkSwitch::withTrashed()->count());
    }
}
