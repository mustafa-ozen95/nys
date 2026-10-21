<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\PolicyAction;
use App\Enums\PrefixMatchType;
use App\Models\BgpGroup;
use App\Models\NetworkSwitch;
use App\Models\SwitchPolicyStatement;
use App\Models\SwitchPolicyTerm;
use App\Models\SwitchPrefixList;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Kullanılmakta olan bir nesne silinemez.
 *
 * Junos silmeye izin verir ama commit'i reddeder — jn32-test üzerinde
 * görüldü: "prefix-list kabul referenced (in term A) but not defined",
 * "Policy gelen referenced but not defined | BGP: import list not applied".
 *
 * Geri alınabilir commit sayesinde cihazda kalıcı bozulma olmaz, ama panel
 * neyin bağlı olduğunu ÖNCEDEN söylemeli ve isteği cihaza hiç götürmemeli.
 */
final class DependencyGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_politikanin_kullandigi_prefix_list_silinemez(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'kabul']);

        $statement = SwitchPolicyStatement::factory()->for($switch, 'switch')->create(['name' => 'gelen']);
        SwitchPolicyTerm::factory()->for($statement, 'statement')->create([
            'name' => 'A',
            'match_prefix_lists' => ['kabul'],
            'match_type' => PrefixMatchType::OrLonger,
            'action' => PolicyAction::Accept,
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->delete(route('switches.prefix-lists.destroy', [$switch, $list]))
            ->assertRedirect()
            ->assertSessionHas('error');

        // İstek cihaza hiç gitmemeli.
        $this->assertNull($device->connection);
        $this->assertDatabaseHas('switch_prefix_lists', ['id' => $list->id]);
    }

    public function test_bgp_grubunun_kullandigi_politika_silinemez(): void
    {
        $switch = $this->switch();
        $statement = SwitchPolicyStatement::factory()->for($switch, 'switch')->create(['name' => 'gelen']);
        SwitchPolicyTerm::factory()->for($statement, 'statement')->create(['name' => 'A']);

        BgpGroup::factory()->for($switch, 'switch')->create([
            'name' => 'erisim',
            'import_policies' => ['gelen'],
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->delete(route('switches.policies.destroy', [$switch, $statement]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($device->connection);
    }

    /**
     * Bağlılık yoksa silme normal akışından geçer; kural yalnızca gerçek
     * bağımlılıkta devreye girmeli, her silmeyi engellememeli.
     */
    public function test_bagli_olmayan_prefix_list_silinebilir(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'bos']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->delete(route('switches.prefix-lists.destroy', [$switch, $list]))
            ->assertRedirect()
            ->assertSessionMissing('error');

        $this->assertNotNull($device->connection);
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
}
