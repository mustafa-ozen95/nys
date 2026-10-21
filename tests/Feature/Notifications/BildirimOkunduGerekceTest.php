<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Okundu işaretle" GEREKÇE ARA KATMANI AÇIKKEN çalışmalı.
 *
 * Bu sınıf ayrı duruyor çünkü TestCase gerekçe ara katmanını varsayılan
 * olarak KAPATIYOR. Kapalıyken yazılan test ucun gerçekte çalışıp
 * çalışmadığını söylemez: "okundu" testleri geçer ama tarayıcıda düğme
 * hiçbir şey yapmaz — uç 422 döner.
 *
 * Form düğmelerini ActionReasonCoverageTest yakalar; bu ise bir fetch
 * çağrısıdır, bu yüzden ara katman açıkken ayrıca sınanır.
 */
final class BildirimOkunduGerekceTest extends TestCase
{
    use RefreshDatabase;

    /** Ara katman bu sınıfta AÇIK çalışır. */
    protected bool $requiresActionReason = true;

    /**
     * Bildirimleri okundu işaretlemek kullanıcının KENDİ sayacını düşürür;
     * hiçbir kaydı ve hiçbir cihazı değiştirmez. Gerekçe istenmez.
     */
    public function test_okundu_isaretlemek_gerekce_istemez(): void
    {
        $user = User::factory()->create(['notifications_seen_at' => null]);

        $this->actingAs($user)
            ->postJson(route('notifications.seen'))
            ->assertOk()
            ->assertJson(['unseen' => 0]);

        $this->assertNotNull($user->fresh()->notifications_seen_at);
    }

    /**
     * Muafiyet yalnızca bu uca aittir; gerekçe isteyen uçlar istemeye devam
     * etmeli, yoksa liste sessizce her şeyi açardı.
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
