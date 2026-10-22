<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Identity\AccountMatcher;
use App\Services\Identity\IdentitySyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MERKEZ HER ZAMAN EZER.
 *
 * Eşitleme iki yönde de işler: merkezde erişimi kalkan burada da kapanır,
 * merkezde geri açılan burada da açılır. Yalnız kapatan bir eşitleme
 * açıklanamayan bir ayrışma doğurur — merkezde "etkin" yazan hesap panelde
 * pasif durur.
 *
 * Panelde yapılan yerel değişiklik YEREL KALIR: burada pasife almak merkezi
 * etkilemez, yalnızca bu panele girişi keser.
 */
final class IdentityMirrorTest extends TestCase
{
    use RefreshDatabase;

    private function centeredUser(UserStatus $status = UserStatus::Active): User
    {
        $user = User::factory()->create(['status' => $status]);

        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => AccountMatcher::PROVIDER,
            'subject' => (string) Str::uuid(),
            'email' => $user->email,
        ]);

        return $user;
    }

    #[Test]
    public function merkezde_erisimi_kalkan_burada_kapanir(): void
    {
        $user = $this->centeredUser();

        app(IdentitySyncer::class)->apply($user, [
            'status' => 'askida', 'can_enter' => false, 'role' => null,
        ]);

        $this->assertSame(UserStatus::Inactive, $user->fresh()->status);
    }

    /** Merkezde geri açılan hesap burada kapalı kalmamalı. */
    #[Test]
    public function merkezde_geri_acilan_burada_da_acilir(): void
    {
        $user = $this->centeredUser(UserStatus::Inactive);

        app(IdentitySyncer::class)->apply($user, [
            'status' => 'etkin', 'can_enter' => true, 'role' => null,
        ]);

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    /**
     * Rol KAPALI hesapta da eşitlenir: atlanınca hesap merkezden geri
     * açıldığında eski rolüyle açılıyordu.
     */
    #[Test]
    public function kapali_hesabin_rolu_de_esitlenir(): void
    {
        $user = $this->centeredUser(UserStatus::Inactive);
        $target = Role::query()->where('is_admin', false)->first() ?? Role::query()->first();

        app(IdentitySyncer::class)->apply($user, [
            'status' => 'askida', 'can_enter' => false, 'role' => $target->slug,
        ]);

        $this->assertSame($target->id, $user->fresh()->role_id);
    }
}
