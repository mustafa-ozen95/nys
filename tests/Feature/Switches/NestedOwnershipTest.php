<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Models\NetworkSwitch;
use App\Models\SwitchIpAddress;
use App\Models\SwitchPort;
use App\Models\SwitchPrefixList;
use App\Models\SwitchPrefixListEntry;
use App\Models\SwitchRoute;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bir switchin adresine başka bir switchin kaydı takılamaz.
 *
 * Yetkilendirme URL'deki switch üzerinden yapılır; alt kayıt doğrulanmazsa
 * A switchinde yetkisi olan biri B switchinin kaydını değiştirebilirdi.
 */
final class NestedOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'update']);

        return $user->fresh();
    }

    public function test_a_vlan_of_another_switch_cannot_be_changed(): void
    {
        $mine = NetworkSwitch::factory()->create();
        $foreign = Vlan::factory()->for(NetworkSwitch::factory()->create(), 'switch')->create(['vlan_tag' => 30]);
        $user = $this->operator();

        $this->actingAs($user)
            ->put(route('switches.vlans.update', [$mine, $foreign]), ['vlan_tag' => 99, 'name' => 'ele-gecirildi'])
            ->assertNotFound();

        $this->actingAs($user)
            ->delete(route('switches.vlans.destroy', [$mine, $foreign]))
            ->assertNotFound();

        $this->assertSame(30, $foreign->fresh()->vlan_tag);
    }

    public function test_an_ip_address_of_another_switch_cannot_be_changed(): void
    {
        $mine = NetworkSwitch::factory()->create();
        $foreign = SwitchIpAddress::factory()
            ->for(NetworkSwitch::factory()->create(), 'switch')
            ->create(['address' => '10.9.9.1']);

        $this->actingAs($this->operator())
            ->delete(route('switches.ip-addresses.destroy', [$mine, $foreign]))
            ->assertNotFound();

        $this->assertDatabaseHas('switch_ip_addresses', ['address' => '10.9.9.1']);
    }

    public function test_a_route_of_another_switch_cannot_be_deleted(): void
    {
        $mine = NetworkSwitch::factory()->create();
        $foreign = SwitchRoute::factory()->for(NetworkSwitch::factory()->create(), 'switch')->create();

        $this->actingAs($this->operator())
            ->delete(route('switches.routes.destroy', [$mine, $foreign]))
            ->assertNotFound();

        $this->assertDatabaseCount('switch_routes', 1);
    }

    public function test_a_prefix_list_of_another_switch_cannot_be_deleted(): void
    {
        $mine = NetworkSwitch::factory()->create();
        $foreign = SwitchPrefixList::factory()->for(NetworkSwitch::factory()->create(), 'switch')->create();

        $this->actingAs($this->operator())
            ->delete(route('switches.prefix-lists.destroy', [$mine, $foreign]))
            ->assertNotFound();

        $this->assertDatabaseCount('switch_prefix_lists', 1);
    }

    public function test_an_entry_of_another_list_cannot_be_deleted(): void
    {
        $switch = NetworkSwitch::factory()->create();
        $mine = SwitchPrefixList::factory()->for($switch, 'switch')->create();
        $foreignList = SwitchPrefixList::factory()->for($switch, 'switch')->create();
        $entry = SwitchPrefixListEntry::factory()->for($foreignList, 'prefixList')->create();

        $this->actingAs($this->operator())
            ->delete(route('switches.prefix-lists.entries.destroy', [$switch, $mine, $entry]))
            ->assertNotFound();

        $this->assertDatabaseCount('switch_prefix_list_entries', 1);
    }

    public function test_a_port_of_another_switch_cannot_be_edited_or_read(): void
    {
        Http::fake(['*' => Http::response(['result' => []])]);

        $mine = NetworkSwitch::factory()->create();
        $foreign = SwitchPort::factory()->for(NetworkSwitch::factory()->create(), 'switch')->create();
        $user = $this->operator();

        $this->actingAs($user)
            ->put(route('switches.ports.update', [$mine, $foreign]), [
                'port_mode' => 'access', 'speed_config' => '',
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson(route('ajax.port.traffic', [$mine, $foreign]).'?range=1h')
            ->assertNotFound();
    }
}
