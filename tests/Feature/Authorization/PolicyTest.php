<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\Server;
use App\Models\SwitchCredential;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('resourceActions')]
    public function test_a_user_without_permissions_is_denied(string $resource, string $action): void
    {
        $user = User::factory()->create();
        $model = self::modelFor($resource);

        $this->assertFalse(
            in_array($action, ['viewAny', 'create'], true)
                ? $user->can($action, $model::class)
                : $user->can($action, $model),
        );
    }

    #[DataProvider('resourceActions')]
    public function test_an_admin_is_always_allowed(string $resource, string $action): void
    {
        $admin = User::factory()->admin()->create();
        $model = self::modelFor($resource);

        $this->assertTrue(
            in_array($action, ['viewAny', 'create'], true)
                ? $admin->can($action, $model::class)
                : $admin->can($action, $model),
        );
    }

    public function test_an_admin_cannot_connect_to_a_switch_without_a_credential(): void
    {
        $admin = User::factory()->admin()->create();
        $switch = NetworkSwitch::factory()->create();

        // Yönetici bypass'ı izinleri geçer, olgusal koşulu geçmez: kimlik
        // bilgisi yoksa bağlantı zaten kurulamaz, düğme de gösterilmemeli.
        $this->assertFalse($admin->can('connect', $switch));

        SwitchCredential::factory()->for($switch, 'switch')->create();

        $this->assertTrue($admin->can('connect', $switch->fresh()));
    }

    public function test_an_admin_cannot_connect_to_an_offline_server(): void
    {
        $admin = User::factory()->admin()->create();
        $offline = Server::factory()->offline()->create(['connection_password' => 'gizli']);
        $online = Server::factory()->create(['connection_password' => 'gizli']);

        $this->assertFalse($admin->can('connect', $offline));
        $this->assertTrue($admin->can('connect', $online));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function resourceActions(): array
    {
        $cases = [];

        foreach (['switches', 'servers', 'addressing', 'users'] as $resource) {
            foreach (['viewAny', 'view', 'create', 'update', 'delete'] as $action) {
                $cases["{$resource}.{$action}"] = [$resource, $action];
            }
        }

        return $cases;
    }

    private static function modelFor(string $resource): NetworkSwitch|Server|AddressBlock|User
    {
        return match ($resource) {
            'switches' => NetworkSwitch::factory()->create(),
            'servers' => Server::factory()->create(),
            'addressing' => AddressBlock::factory()->create(),
            'users' => User::factory()->create(),
        };
    }

    public function test_a_wildcard_grant_opens_only_the_granted_action(): void
    {
        $user = User::factory()->create();
        $switch = NetworkSwitch::factory()->create();

        UserPermission::factory()->for($user)->create(['resource' => 'switches', 'action' => 'view']);

        $user = $user->fresh();

        $this->assertTrue($user->can('view', $switch));
        $this->assertTrue($user->can('viewAny', NetworkSwitch::class));
        $this->assertFalse($user->can('update', $switch));
        $this->assertFalse($user->can('delete', $switch));
    }

    public function test_viewing_a_switch_does_not_grant_permission_to_connect(): void
    {
        $user = User::factory()->create();
        $switch = NetworkSwitch::factory()->create();
        SwitchCredential::factory()->for($switch, 'switch')->create();

        UserPermission::factory()->for($user)->create(['resource' => 'switches', 'action' => 'view']);

        $user = $user->fresh();

        $this->assertTrue($user->can('view', $switch));
        $this->assertFalse($user->can('connect', $switch));
    }

    public function test_connecting_requires_both_the_grant_and_a_stored_credential(): void
    {
        $user = User::factory()->create();
        $withCredential = NetworkSwitch::factory()->create();
        $withoutCredential = NetworkSwitch::factory()->create();

        SwitchCredential::factory()->for($withCredential, 'switch')->create();

        foreach (['view', 'connect'] as $action) {
            UserPermission::factory()->for($user)->create(['resource' => 'switches', 'action' => $action]);
        }

        $user = $user->fresh();

        $this->assertTrue($user->can('connect', $withCredential));
        $this->assertFalse($user->can('connect', $withoutCredential));
    }

    public function test_a_denied_switch_cannot_be_connected_to_even_with_a_connect_grant(): void
    {
        $user = User::factory()->create();
        $switch = NetworkSwitch::factory()->create();
        SwitchCredential::factory()->for($switch, 'switch')->create();

        foreach (['view', 'connect'] as $action) {
            UserPermission::factory()->for($user)->create(['resource' => 'switches', 'action' => $action]);
        }

        UserPermission::factory()->for($user)->forRecord($switch->id)->deny()->create([
            'resource' => 'switches', 'action' => 'view',
        ]);

        $this->assertFalse($user->fresh()->can('connect', $switch));
    }

    public function test_an_offline_server_cannot_be_connected_to(): void
    {
        $user = User::factory()->create();
        $online = Server::factory()->create();
        $offline = Server::factory()->offline()->create();

        foreach (['view', 'connect'] as $action) {
            UserPermission::factory()->for($user)->create(['resource' => 'servers', 'action' => $action]);
        }

        $user = $user->fresh();

        $this->assertTrue($user->can('connect', $online));
        $this->assertFalse($user->can('connect', $offline));
    }

    public function test_a_user_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();

        $this->assertFalse($admin->can('delete', $admin));
        $this->assertTrue($admin->can('delete', $other));
    }

    public function test_only_an_admin_can_manage_permissions(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        foreach (['view', 'update'] as $action) {
            UserPermission::factory()->for($user)->create(['resource' => 'users', 'action' => $action]);
        }

        $this->assertTrue($admin->can('managePermissions', $user));
        $this->assertFalse($user->fresh()->can('managePermissions', $admin));
    }
}
