<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sessiz kayıp güncelleme engeli.
 *
 * İki kullanıcı aynı kaydı aynı anda düzenlerse son yazan sessizce
 * kazanmamalı: form açıldıktan sonra kayıt değiştiyse gönderim reddedilir.
 */
final class RecordVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_kayit_form_acildiktan_sonra_degistiyse_yazma_reddedilir(): void
    {
        $recipient = AnnouncementRecipient::factory()->create(['label' => 'NOC']);
        $oldVersion = $recipient->updated_at->getTimestamp();

        // Başka biri araya girip kaydı değiştirdi.
        $this->travel(2)->seconds();
        $recipient->update(['label' => 'BASKASI YAZDI']);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('announcements.recipients.update', $recipient), [
                'record_version' => $oldVersion,
                'label' => 'BENIM YAZDIGIM',
                'email' => $recipient->email,
                'locale' => $recipient->locale->value,
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('record_version');

        // Araya giren kaydın değişikliği yerinde kalır; sessizce ezilmez.
        $this->assertSame('BASKASI YAZDI', $recipient->fresh()->label);
    }

    public function test_guncel_surumle_yazma_calisir(): void
    {
        $recipient = AnnouncementRecipient::factory()->create(['label' => 'NOC']);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('announcements.recipients.update', $recipient), [
                'record_version' => $recipient->updated_at->getTimestamp(),
                'label' => 'YENI AD',
                'email' => $recipient->email,
                'locale' => $recipient->locale->value,
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('YENI AD', $recipient->fresh()->label);
    }

    public function test_surum_tasimayan_istek_etkilenmez(): void
    {
        $recipient = AnnouncementRecipient::factory()->create(['label' => 'NOC']);

        // Yeni kayıt formlarında sürüm damgası yoktur; kontrol devreye girmez.
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('announcements.recipients.update', $recipient), [
                'label' => 'SURUMSUZ',
                'email' => $recipient->email,
                'locale' => $recipient->locale->value,
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('SURUMSUZ', $recipient->fresh()->label);
    }
}
