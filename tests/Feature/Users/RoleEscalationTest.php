<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Kullanıcıları düzenle" yetkisi, yönetici olma yetkisi değildir.
 */
final class RoleEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /* Bu sınıf yetki düzeneğini sınıyor; üretimde kapalı (bkz. TestCase). */
        $this->permissionDistributionOpen();
    }

    private function role(string $slug): Role
    {
        return Role::query()->where('slug', $slug)->firstOrFail();
    }

    private function editor(): User
    {
        $user = User::factory()->network()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'users', ['view', 'create', 'update']);

        return $user->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Deneme Kullanıcı',
            'email' => 'deneme@ornek.test',
            'status' => 'active',
            'password' => 'CokGuclu123parola',
            'password_confirmation' => 'CokGuclu123parola',
        ], $overrides);
    }

    public function test_an_editor_cannot_make_themselves_an_admin(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->put(route('users.update', $editor), $this->payload([
                'email' => $editor->email,
                'role' => $this->role('admin')->uuid,
            ]))
            ->assertSessionHasErrors('role');

        $this->assertSame('network', $editor->fresh()->role->slug);
    }

    public function test_an_editor_cannot_promote_someone_else(): void
    {
        $target = User::factory()->role('teknik')->create();

        $this->actingAs($this->editor())
            ->put(route('users.update', $target), $this->payload([
                'email' => $target->email,
                'role' => $this->role('admin')->uuid,
            ]))
            ->assertSessionHasErrors('role');

        $this->assertSame('teknik', $target->fresh()->role->slug);
    }

    public function test_a_user_created_by_an_editor_gets_the_lowest_role(): void
    {
        $this->actingAs($this->editor())
            ->post(route('users.store'), $this->payload())
            ->assertRedirect();

        $this->assertSame(
            'teknik',
            User::query()->firstWhere('email', 'deneme@ornek.test')->role->slug,
        );
    }

    public function test_an_editor_can_still_change_other_fields(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->put(route('users.update', $editor), $this->payload(['email' => $editor->email, 'name' => 'Yeni Ad']))
            ->assertRedirect();

        $this->assertSame('Yeni Ad', $editor->fresh()->name);
        $this->assertSame('network', $editor->fresh()->role->slug);
    }

    /**
     * Rolü YÜKSELTMEK engelliydi; rolü zaten yüksek olan hesabı ELE GEÇİRMEK
     * engelli değildi.
     *
     * "Kullanıcıları düzenle" yetkisine sahip biri bir yöneticinin parolasını
     * değiştirip onun hesabıyla giriş yapabiliyordu — rol değiştirmeye hiç
     * gerek yoktu. Aynı sonuca giden ikinci kapı.
     */
    public function test_an_editor_cannot_reset_an_admins_password(): void
    {
        $admin = User::factory()->create([
            'role_id' => $this->role('admin')->id,
            'password' => Hash::make('EskiParola123'),
        ]);

        $this->actingAs($this->editor())
            ->put(route('users.update', $admin), $this->payload([
                'name' => $admin->name,
                'email' => $admin->email,
                'password' => 'EleGecirdim123',
                'password_confirmation' => 'EleGecirdim123',
            ]))
            ->assertForbidden();

        $this->assertTrue(Hash::check('EskiParola123', $admin->fresh()->password));
    }

    /**
     * Yöneticiyi askıya almak da aynı kapıdır: bütün yöneticiler pasife
     * çekilirse panel kilitlenir.
     */
    public function test_an_editor_cannot_suspend_an_admin(): void
    {
        $admin = User::factory()->create(['role_id' => $this->role('admin')->id]);

        $this->actingAs($this->editor())
            ->put(route('users.update', $admin), $this->payload([
                'name' => $admin->name,
                'email' => $admin->email,
                'status' => 'banned',
            ]))
            ->assertForbidden();

        $this->assertSame(UserStatus::Active, $admin->fresh()->status);
    }

    public function test_an_editor_cannot_delete_an_admin(): void
    {
        $admin = User::factory()->create(['role_id' => $this->role('admin')->id]);

        $this->actingAs($this->editor())
            ->delete(route('users.destroy', $admin))
            ->assertForbidden();

        $this->assertNotNull($admin->fresh());
    }

    public function test_an_editor_cannot_delete_a_regular_user_either(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->editor())
            ->delete(route('users.destroy', $target))
            ->assertForbidden();

        $this->assertNotNull($target->fresh());
    }

    /**
     * Koruma yalnızca YÖNETİCİ hedefler içindir; sıradan bir kullanıcıyı
     * düzenlemek yetkinin kendisidir ve engellenmemeli.
     */
    public function test_an_editor_can_still_edit_a_regular_user(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->editor())
            ->put(route('users.update', $target), $this->payload([
                'name' => 'Yeni Ad',
                'email' => $target->email,
            ]))
            ->assertRedirect();

        $this->assertSame('Yeni Ad', $target->fresh()->name);
    }

    public function test_an_admin_can_still_set_roles(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->put(route('users.update', $target), $this->payload([
                'email' => $target->email,
                'role' => $this->role('network')->uuid,
            ]))
            ->assertRedirect();

        $this->assertSame('network', $target->fresh()->role->slug);
    }
}
