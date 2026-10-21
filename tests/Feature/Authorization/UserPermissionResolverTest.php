<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\PermissionEffect;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Karar sırası (ilk eşleşen kazanır):
 *   1. kayda özel DENY  2. kayda özel ALLOW
 *   3. wildcard DENY    4. wildcard ALLOW    5. eşleşme yok -> red
 */
final class UserPermissionResolverTest extends TestCase
{
    use RefreshDatabase;

    private UserPermissionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(UserPermissionResolver::class);
    }

    /**
     * @param  array<int, array{int, PermissionEffect}>  $rows  [resource_id, effect]
     */
    #[DataProvider('decisionTable')]
    public function test_decision_table(array $rows, bool $expected): void
    {
        $user = User::factory()->create();
        $switch = NetworkSwitch::factory()->create();

        foreach ($rows as [$resourceId, $effect]) {
            UserPermission::factory()->for($user)->create([
                'resource' => 'switches',
                'resource_id' => $resourceId === 0 ? UserPermission::ALL_RECORDS : $switch->id,
                'action' => 'view',
                'effect' => $effect,
            ]);
        }

        $this->assertSame(
            $expected,
            $this->resolver->allows($user->fresh(), 'switches', 'view', $switch->id),
        );
    }

    /**
     * @return array<string, array{array<int, array{int, PermissionEffect}>, bool}>
     */
    public static function decisionTable(): array
    {
        return [
            '1. kayda özel deny kazanır' => [[[1, PermissionEffect::Deny]], false],
            '2. kayda özel allow kazanır' => [[[1, PermissionEffect::Allow]], true],
            '3. wildcard deny reddeder' => [[[0, PermissionEffect::Deny]], false],
            '4. wildcard allow izin verir' => [[[0, PermissionEffect::Allow]], true],
            '5. eşleşme yoksa reddedilir' => [[], false],

            'kayıt deny wildcard allow’u ezer' => [
                [[0, PermissionEffect::Allow], [1, PermissionEffect::Deny]],
                false,
            ],
            'kayıt allow wildcard deny’i ezer' => [
                [[0, PermissionEffect::Deny], [1, PermissionEffect::Allow]],
                true,
            ],
        ];
    }

    public function test_wildcard_deny_is_honoured_for_other_records(): void
    {
        $user = User::factory()->create();
        $allowed = NetworkSwitch::factory()->create();
        $other = NetworkSwitch::factory()->create();

        UserPermission::factory()->for($user)->create([
            'resource' => 'switches', 'resource_id' => UserPermission::ALL_RECORDS,
            'action' => 'view', 'effect' => PermissionEffect::Deny,
        ]);
        UserPermission::factory()->for($user)->create([
            'resource' => 'switches', 'resource_id' => $allowed->id,
            'action' => 'view', 'effect' => PermissionEffect::Allow,
        ]);

        $user = $user->fresh();

        $this->assertTrue($this->resolver->allows($user, 'switches', 'view', $allowed->id));
        $this->assertFalse($this->resolver->allows($user, 'switches', 'view', $other->id));
    }

    public function test_permissions_do_not_leak_across_actions_or_resources(): void
    {
        $user = User::factory()->create();
        $switch = NetworkSwitch::factory()->create();

        UserPermission::factory()->for($user)->create([
            'resource' => 'switches', 'resource_id' => UserPermission::ALL_RECORDS,
            'action' => 'view', 'effect' => PermissionEffect::Allow,
        ]);

        $user = $user->fresh();

        $this->assertTrue($this->resolver->allows($user, 'switches', 'view', $switch->id));
        $this->assertFalse($this->resolver->allows($user, 'switches', 'update', $switch->id));
        $this->assertFalse($this->resolver->allows($user, 'servers', 'view', $switch->id));
    }

    public function test_admin_bypasses_every_check(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertTrue($this->resolver->allows($admin, 'switches', 'delete', 999));
        $this->assertTrue($this->resolver->allowsAny($admin, 'logs', 'view'));
    }

    public function test_allows_any_reflects_record_level_grants(): void
    {
        $user = User::factory()->create();
        $switch = NetworkSwitch::factory()->create();

        $this->assertFalse($this->resolver->allowsAny($user->fresh(), 'switches', 'view'));

        UserPermission::factory()->for($user)->forRecord($switch->id)->create();

        $this->assertTrue($this->resolver->allowsAny($user->fresh(), 'switches', 'view'));
    }

    public function test_scope_returns_everything_minus_denied_records_under_a_wildcard_allow(): void
    {
        $user = User::factory()->create();
        $visible = NetworkSwitch::factory()->create();
        $hidden = NetworkSwitch::factory()->create();

        UserPermission::factory()->for($user)->create([
            'resource' => 'switches', 'resource_id' => UserPermission::ALL_RECORDS,
            'action' => 'view', 'effect' => PermissionEffect::Allow,
        ]);
        UserPermission::factory()->for($user)->create([
            'resource' => 'switches', 'resource_id' => $hidden->id,
            'action' => 'view', 'effect' => PermissionEffect::Deny,
        ]);

        $ids = NetworkSwitch::query()->visibleTo($user->fresh())->pluck('id');

        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($hidden->id));
    }

    public function test_scope_returns_only_granted_records_without_a_wildcard(): void
    {
        $user = User::factory()->create();
        $granted = NetworkSwitch::factory()->create();
        NetworkSwitch::factory()->create();

        UserPermission::factory()->for($user)->forRecord($granted->id)->create();

        $this->assertSame([$granted->id], NetworkSwitch::query()->visibleTo($user->fresh())->pluck('id')->all());
    }

    public function test_scope_returns_nothing_when_the_user_has_no_permissions(): void
    {
        NetworkSwitch::factory()->count(3)->create();

        $this->assertSame(0, NetworkSwitch::query()->visibleTo(User::factory()->create())->count());
    }

    public function test_scope_returns_everything_for_an_admin(): void
    {
        NetworkSwitch::factory()->count(3)->create();

        $this->assertSame(3, NetworkSwitch::query()->visibleTo(User::factory()->admin()->create())->count());
    }

    public function test_sync_resource_replaces_previous_rows_in_one_transaction(): void
    {
        $user = User::factory()->create();
        $switch = NetworkSwitch::factory()->create();

        UserPermission::factory()->for($user)->resource('switches', 'delete')->create();

        $this->resolver->syncResource(
            $user,
            'switches',
            wildcardActions: ['view'],
            allowedRecords: ['update' => [$switch->id]],
            deniedRecords: ['view' => [$switch->id]],
        );

        $rows = $user->fresh()->permissions;

        $this->assertCount(3, $rows);
        $this->assertFalse($rows->contains(fn (UserPermission $row): bool => $row->action === 'delete'));
        $this->assertTrue($this->resolver->allows($user->fresh(), 'switches', 'update', $switch->id));
        $this->assertFalse($this->resolver->allows($user->fresh(), 'switches', 'view', $switch->id));
    }

    public function test_sync_resource_leaves_other_resources_untouched(): void
    {
        $user = User::factory()->create();
        UserPermission::factory()->for($user)->resource('servers', 'view')->create();

        $this->resolver->syncResource($user, 'switches', ['view']);

        $this->assertTrue($this->resolver->allowsAny($user->fresh(), 'servers', 'view'));
    }
}
