<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Enums\PanelMode;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ayrıntı düzeyi düğmesi GEREKÇE ARA KATMANI AÇIKKEN çalışmalı.
 *
 * Bu test ayrı bir sınıfta duruyor çünkü TestCase gerekçe ara katmanını
 * varsayılan olarak KAPATIYOR. Kapalıyken yazılan bir test, ucun gerçekte
 * çalışıp çalışmadığını söylemez: mod düğmesi tam olarak böyle kaçtı —
 * testler geçiyordu, tarayıcıda tıklayınca hiçbir şey olmuyordu.
 *
 * Durum değiştiren yeni bir uç eklerken sorulacak soru: "bu uç gerekçe
 * isteyecek mi?" Cevap hayırsa RequireActionReason::IGNORED içine girer ve
 * kilidi BURADA, ara katman açıkken kurulur.
 */
final class PanelModeReasonTest extends TestCase
{
    use RefreshDatabase;

    /** Ara katman bu sınıfta AÇIK çalışır. */
    protected bool $requiresActionReason = true;

    public function test_mod_degistirmek_gerekce_istemez(): void
    {
        $user = User::factory()->create(['panel_mode' => PanelMode::Advanced]);
        /* Gelişmiş mod rol yetkisine bağlı. */
        app(UserPermissionResolver::class)->syncResource($user, 'gorunum_modu', ['view']);

        $this->actingAs($user)
            ->post(route('panel-mode.toggle'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(PanelMode::Simple, $user->fresh()->panel_mode);
    }

    /**
     * Muafiyet yalnızca bu uca aittir: gerekçe isteyen uçlar istemeye devam
     * etmeli, yoksa muafiyet listesi sessizce her şeyi açardı.
     */
    public function test_gerekce_isteyen_uclar_hala_istiyor(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('users.store'), [
                'name' => 'Deneme',
                'email' => 'deneme@ornek.test',
                'password' => 'CokGizliParola1!',
                'password_confirmation' => 'CokGizliParola1!',
            ])
            ->assertSessionHasErrors('reason');
    }
}
