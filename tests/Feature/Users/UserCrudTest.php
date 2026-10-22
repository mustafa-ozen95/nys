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

final class UserCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Yeni Kullanıcı',
            'email' => 'yeni@example.test',
            'password' => 'guclu-parola-2026',
            'password_confirmation' => 'guclu-parola-2026',
            'role' => Role::query()->where('slug', 'teknik')->value('uuid'),
            'status' => 'active',
        ], $overrides);
    }

    public function test_a_regular_user_cannot_reach_the_list(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('users.index'))
            ->assertForbidden();
    }

    public function test_an_admin_sees_the_list(): void
    {
        User::factory()->create(['name' => 'Listede Görünen']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Listede Görünen');
    }

    public function test_an_admin_can_create_a_user(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('users.store'), $this->payload())
            ->assertRedirect(route('users.index'));

        $this->assertSame(
            'teknik',
            User::query()->firstWhere('email', 'yeni@example.test')->role->slug,
        );
        $this->assertDatabaseHas('users', ['email' => 'yeni@example.test']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'created']);
    }

    public function test_the_password_is_stored_hashed(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('users.store'), $this->payload());

        $user = User::query()->firstWhere('email', 'yeni@example.test');

        $this->assertNotSame('guclu-parola-2026', $user->password);
        $this->assertTrue(Hash::check('guclu-parola-2026', $user->password));
    }

    public function test_a_weak_password_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('users.store'), $this->payload([
                'password' => 'kisa',
                'password_confirmation' => 'kisa',
            ]))
            ->assertSessionHasErrors('password');
    }

    public function test_an_unconfirmed_password_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('users.store'), $this->payload(['password_confirmation' => 'baska-parola']))
            ->assertSessionHasErrors('password');
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'yeni@example.test']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('users.store'), $this->payload())
            ->assertSessionHasErrors('email');
    }

    public function test_updating_without_a_password_keeps_the_current_one(): void
    {
        $user = User::factory()->create(['password' => Hash::make('eski-parola-2026')]);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('users.update', $user), $this->payload([
                'name' => 'Güncel Ad',
                'email' => $user->email,
                'password' => '',
                'password_confirmation' => '',
            ]))
            ->assertRedirect(route('users.index'));

        $user->refresh();

        $this->assertSame('Güncel Ad', $user->name);
        $this->assertTrue(Hash::check('eski-parola-2026', $user->password));
    }

    public function test_an_admin_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->delete(route('users.destroy', $admin))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_an_admin_can_delete_another_user(): void
    {
        $target = User::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('users.destroy', $target))
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    /**
     * HESAP AÇILIR AÇILMAZ ROLÜN YETKİLERİ GELİR.
     *
     * Sıfır yetkiyle doğsaydı her hesap için yetki ekranına gidip "şablonu
     * uygula" demek gerekirdi — rol verilmiş ama hiçbir şey göremeyen bir
     * hesap, kullanıcıya panelin bozuk olduğunu düşündürüyordu.
     */
    public function test_yeni_hesap_rolun_yetkileriyle_calisir(): void
    {
        $network = Role::query()->where('slug', 'network')->firstOrFail();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('users.store'), $this->payload(['role' => $network->uuid]))
            ->assertRedirect();

        $new = User::where('email', 'yeni@example.test')->firstOrFail();

        $this->assertSame($network->id, $new->role_id);
        $this->assertSame(0, $new->permissions()->count(), 'Yetki kopyalanmamalı.');
        $this->assertTrue(
            app(UserPermissionResolver::class)->allows($new, 'servers', 'view'),
            'Rolün yetkisi geçerli olmalı.',
        );
    }

    /**
     * ROL DEĞİŞİNCE YETKİLER DE ONUNLA GELİR.
     *
     * Yalnız ad değişip yetkiler eski rolde kalmamalı; ayrıca "şablonu
     * uygula" demek gerekmemeli. Rol, yetkinin adıdır.
     */
    public function test_rol_degisince_yeni_rolun_yetkileri_gecerli_olur(): void
    {
        $technical = Role::query()->where('slug', 'teknik')->firstOrFail();
        $network = Role::query()->where('slug', 'network')->firstOrFail();

        $user = User::factory()->create(['role_id' => $technical->id]);
        $previous = $user->permissions()->count();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $network->uuid,
                'status' => 'active',
                'reason' => 'ağ ekibine geçti',
            ])
            ->assertRedirect();

        $fresh = $user->fresh();

        $parser = app(UserPermissionResolver::class);

        $this->assertSame($network->id, $fresh->role_id);
        $this->assertSame($previous, $fresh->permissions()->count(), 'İstisnalara dokunulmamalı.');
        $this->assertTrue($parser->allows($fresh, 'servers', 'view'), 'Yeni rolün yetkisi geçerli olmalı.');
    }

    /** Rol AYNI kaldığında şablon uygulanmaz: elle verilmiş istisnalar silinmemeli. */
    public function test_rol_ayni_kalinca_yetkilere_dokunulmaz(): void
    {
        $technical = Role::query()->where('slug', 'teknik')->firstOrFail();
        $user = User::factory()->create(['role_id' => $technical->id]);

        app(UserPermissionResolver::class)
            ->syncResource($user, 'servers', ['view', 'create', 'update', 'delete']);

        $previous = $user->permissions()->count();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('users.update', $user), [
                'name' => 'Yeni Ad',
                'email' => $user->email,
                'role' => $technical->uuid,
                'status' => 'active',
                'reason' => 'ad düzeltmesi',
            ])
            ->assertRedirect();

        $this->assertSame($previous, $user->fresh()->permissions()->count());
    }

    /**
     * PASİF HESAP KENDİ PROFİLİNE DE GİREMEZ.
     *
     * "hesap" grubu etkinlik kontrolünü taşımıyordu: pasife alınmış biri
     * panelin her sayfasından atılırken kendi profilini ve parolasını
     * değiştirmeye devam edebiliyordu.
     */
    public function test_pasif_hesap_kendi_profiline_giremez(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account.profile'))->assertOk();

        $user->forceFill(['status' => UserStatus::Inactive])->save();

        $this->actingAs($user->fresh())
            ->get(route('account.profile'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
