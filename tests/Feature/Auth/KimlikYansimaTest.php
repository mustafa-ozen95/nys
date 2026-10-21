<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Kimlik\HesapEslestirici;
use App\Services\Kimlik\KimlikEsitleyici;
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
final class KimlikYansimaTest extends TestCase
{
    use RefreshDatabase;

    private function merkezliKullanici(UserStatus $durum = UserStatus::Active): User
    {
        $kullanici = User::factory()->create(['status' => $durum]);

        UserIdentity::create([
            'user_id' => $kullanici->id,
            'provider' => HesapEslestirici::SAGLAYICI,
            'subject' => (string) Str::uuid(),
            'email' => $kullanici->email,
        ]);

        return $kullanici;
    }

    #[Test]
    public function merkezde_erisimi_kalkan_burada_kapanir(): void
    {
        $kullanici = $this->merkezliKullanici();

        app(KimlikEsitleyici::class)->uygula($kullanici, [
            'durum' => 'askida', 'girebilir' => false, 'rol' => null,
        ]);

        $this->assertSame(UserStatus::Inactive, $kullanici->fresh()->status);
    }

    /** Merkezde geri açılan hesap burada kapalı kalmamalı. */
    #[Test]
    public function merkezde_geri_acilan_burada_da_acilir(): void
    {
        $kullanici = $this->merkezliKullanici(UserStatus::Inactive);

        app(KimlikEsitleyici::class)->uygula($kullanici, [
            'durum' => 'etkin', 'girebilir' => true, 'rol' => null,
        ]);

        $this->assertSame(UserStatus::Active, $kullanici->fresh()->status);
    }

    /**
     * Rol KAPALI hesapta da eşitlenir: atlanınca hesap merkezden geri
     * açıldığında eski rolüyle açılıyordu.
     */
    #[Test]
    public function kapali_hesabin_rolu_de_esitlenir(): void
    {
        $kullanici = $this->merkezliKullanici(UserStatus::Inactive);
        $hedef = Role::query()->where('is_admin', false)->first() ?? Role::query()->first();

        app(KimlikEsitleyici::class)->uygula($kullanici, [
            'durum' => 'askida', 'girebilir' => false, 'rol' => $hedef->slug,
        ]);

        $this->assertSame($hedef->id, $kullanici->fresh()->role_id);
    }
}
