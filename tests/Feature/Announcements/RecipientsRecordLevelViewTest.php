<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Models\AnnouncementMail;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Alıcılarda kayıt düzeyinde "görme" yasağı olan kullanıcı, o alıcıyı
 * listede/formda göremez ve ona anons talebi gönderemez.
 */
final class RecipientsRecordLevelViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();
    }

    public function test_yasakli_alici_listede_ve_formda_gorunmez(): void
    {
        $forbidden = AnnouncementRecipient::factory()->create(['label' => 'Yasakli Esle', 'email' => 'yasakli@ornek.test']);
        AnnouncementRecipient::factory()->create(['label' => 'Acik Esle', 'email' => 'acik@ornek.test']);

        $user = $this->operator($forbidden->id);

        $this->actingAs($user)->get(route('announcements.create'))
            ->assertOk()
            ->assertSee('acik@ornek.test')
            ->assertDontSee('yasakli@ornek.test');

        $this->actingAs($user)->get(route('announcements.recipients.index'))
            ->assertOk()
            ->assertSee('acik@ornek.test')
            ->assertDontSee('yasakli@ornek.test');
    }

    public function test_yasakli_aliciya_gonderim_reddedilir(): void
    {
        $forbidden = AnnouncementRecipient::factory()->create();

        $this->actingAs($this->operator($forbidden->id))
            ->post(route('announcements.send'), [
                'prefixes' => '192.0.2.0/24',
                'asn' => 'AS64496',
                'downstream' => '',
                'recipients' => [$forbidden->uuid],
            ])
            ->assertSessionHasErrors('recipients.0');

        $this->assertSame(0, AnnouncementMail::query()->count());
        Queue::assertNothingPushed();
    }

    private function operator(int $forbiddenId): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)
            ->syncResource($user, 'announcements', ['view', 'create'], [], ['view' => [$forbiddenId]]);

        return $user->fresh();
    }
}
