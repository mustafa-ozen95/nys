<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gerekçesiz işlem yapılamaz.
 *
 * Denetimde sorulan soru "kim ne yaptı" kadar "neden yaptı"dır. Gerekçe
 * kullanıcıdan onay penceresinde alınır ama kontrol SUNUCUDADIR: pencereyi
 * atlayıp doğrudan istek göndermek işe yaramaz.
 */
final class ActionReasonTest extends TestCase
{
    use RefreshDatabase;

    /** Bu testin konusu kuralın kendisi; ara katman açık çalışır. */
    protected bool $requiresActionReason = true;

    public function test_gerekcesiz_yazma_reddedilir(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('announcements.recipients.store'), [
                'label' => 'NOC',
                'email' => 'noc@ornek.test',
                'locale' => 'tr',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('announcement_recipients', 0);
    }

    public function test_cok_kisa_gerekce_reddedilir(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('announcements.recipients.store'), [
                'label' => 'NOC',
                'email' => 'noc@ornek.test',
                'locale' => 'tr',
                'reason' => 'ok',
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_gerekceli_yazma_calisir_ve_kayda_gecer(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('announcements.recipients.store'), [
                'label' => 'NOC',
                'email' => 'noc@ornek.test',
                'locale' => 'tr',
                'reason' => 'Yeni NOC ekibi devreye alındı',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('announcement_recipients', ['email' => 'noc@ornek.test']);

        // Gerekçe etkinlik kaydına yazılır ve hash zincirine girer.
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'created',
            'reason' => 'Yeni NOC ekibi devreye alındı',
        ]);
    }

    public function test_silme_de_gerekce_ister(): void
    {
        $recipient = AnnouncementRecipient::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('announcements.recipients.destroy', $recipient))
            ->assertSessionHasErrors('reason');

        $this->assertModelExists($recipient);
    }

    public function test_okuma_gerekce_istemez(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('announcements.recipients.index'))
            ->assertOk();
    }

    public function test_giris_gerekce_istemez(): void
    {
        $user = User::factory()->create(['password' => bcrypt('parola-1234')]);

        // Oturum açılmadan gerekçe istenemez; giriş ekranı kilitlenmemeli.
        $this->post(route('login'), ['email' => $user->email, 'password' => 'parola-1234'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }
}
