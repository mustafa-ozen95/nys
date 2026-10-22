<?php

declare(strict_types=1);

namespace Tests\Feature\Addressing;

use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SwitchTargetTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        return $user->fresh();
    }

    public function test_it_lists_the_vlans_and_ports_of_a_switch(): void
    {
        $switch = NetworkSwitch::factory()->create();
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30, 'name' => 'kamera']);
        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/12', 'if_index' => 12, 'description' => 'NVR',
        ]);

        $this->actingAs($this->operator())
            ->getJson(route('ajax.switches.targets', $switch))
            ->assertOk()
            ->assertJsonPath('vlans.0.value', $vlan->uuid)
            ->assertJsonPath('vlans.0.label', '30 · kamera')
            ->assertJsonPath('ports.0.value', 'ge-0/0/12')
            ->assertJsonPath('ports.0.label', 'ge-0/0/12 · NVR');
    }

    /**
     * Etiketsiz VLAN da hedef olabilir.
     *
     * Hedefin değeri etiket değil kayıttır; etiket olsaydı etiketsiz VLAN,
     * üstünde müşteri IP'si taşısa bile, listeye hiç giremezdi. Sıralama
     * etikete göre kalır, etiketsizler sona düşer.
     */
    public function test_etiketsiz_vlan_da_hedef_listesinde(): void
    {
        $switch = NetworkSwitch::factory()->create();
        Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30, 'name' => 'kamera']);
        $untagged = Vlan::factory()->for($switch, 'switch')
            ->create(['vlan_tag' => null, 'name' => 'V27', 'l3_interface' => 'vlan.28']);

        $this->actingAs($this->operator())
            ->getJson(route('ajax.switches.targets', $switch))
            ->assertOk()
            ->assertJsonCount(2, 'vlans')
            ->assertJsonPath('vlans.1.value', $untagged->uuid)
            ->assertJsonPath('vlans.1.label', 'etiketsiz · V27');
    }

    public function test_targets_of_another_switch_are_not_returned(): void
    {
        $switch = NetworkSwitch::factory()->create();
        $other = NetworkSwitch::factory()->create();
        Vlan::factory()->for($other, 'switch')->create(['vlan_tag' => 99]);

        $this->actingAs($this->operator())
            ->getJson(route('ajax.switches.targets', $switch))
            ->assertOk()
            ->assertJsonCount(0, 'vlans');
    }

    public function test_it_refuses_a_user_who_cannot_see_the_switch(): void
    {
        $switch = NetworkSwitch::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson(route('ajax.switches.targets', $switch))
            ->assertForbidden();
    }
}
