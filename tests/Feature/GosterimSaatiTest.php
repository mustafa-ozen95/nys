<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zaman EKRANDA yerel saatte görünür; saklama UTC kalır.
 *
 * Ekran UTC gösterirse yerel saati UTC'den farklı olan kullanıcı olayları
 * saat farkı kadar önce ya da sonra olmuş sanır.
 *
 * Saklama DEĞİŞMEZ: geçmiş kayıtları yeniden yorumlamak, hash zincirine
 * giren denetim kayıtlarının anlamını değiştirirdi.
 */
final class GosterimSaatiTest extends TestCase
{
    use RefreshDatabase;

    public function test_saklama_utc_kalir(): void
    {
        $this->assertSame('UTC', config('app.timezone'), 'Saklama saat dilimi değişmiş.');
    }

    public function test_kayit_ekraninda_yerel_saat_gorunur(): void
    {
        $user = User::factory()->admin()->create();

        // UTC 12:00 = Europe/Istanbul 15:00
        $this->travelTo('2026-09-08 12:00:00', function () use ($user): void {
            $this->actingAs($user)->get(route('logs.index'));
        });

        $this->actingAs($user)
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('08.09.2026 15:00:00')
            ->assertDontSee('08.09.2026 12:00:00');
    }

    /**
     * Bileşen ham zamanı da taşır: makine okuyabilsin, tarayıcı ipucunda
     * dilim görünsün.
     */
    public function test_ham_zaman_isaretlemede_durur(): void
    {
        $user = User::factory()->admin()->create();

        $this->travelTo('2026-09-08 12:00:00', function () use ($user): void {
            $this->actingAs($user)->get(route('logs.index'));
        });

        $this->actingAs($user)
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('datetime="2026-09-08T12:00:00+00:00"', false);
    }
}
