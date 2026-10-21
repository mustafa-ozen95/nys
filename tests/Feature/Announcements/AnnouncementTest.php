<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Enums\MailStatus;
use App\Jobs\SendAnnouncementJob;
use App\Models\AnnouncementMail;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Hiçbir testte gerçek e-posta gönderilmez: kuyruk sahtelenir.
 */
final class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bu dosya metnin üretimini ve yetkiyi sınar, anons HAKKINI değil.
     *
     * Yetki denetimi kapatılır: açık bırakılsaydı her testin ayrıca RIPEstat
     * ve IRR yanıtı hazırlaması gerekir, hazırlamayı unutan test de "mail
     * gitmedi" diye başka bir sebepten düşerdi. Denetimin kendisi
     * AnnouncementPermissionTest içinde, üretimin geçtiği yoldan sınanır.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['announcements.authorization.enabled' => false]);
    }

    /** @var array<string, AnnouncementRecipient>|null */
    private ?array $recipients = null;

    /**
     * Anons göndermek yetki ister; yetkisiz hesap dış dünyaya mail atamaz.
     *
     * @param  array<int, string>  $actions
     */
    private function operator(array $actions = ['view', 'create']): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'announcements', $actions);

        return $user->fresh();
    }

    /**
     * Alıcılar veritabanından yönetilir; testler kendi alıcılarını kurar.
     *
     * @return array<string, AnnouncementRecipient>
     */
    private function recipients(): array
    {
        if ($this->recipients !== null) {
            return $this->recipients;
        }

        return $this->recipients = [
            'noc' => AnnouncementRecipient::factory()->create(['label' => 'NOC', 'email' => 'noc@ornek.test']),
            'peering' => AnnouncementRecipient::factory()->english()->create([
                'label' => 'Peering', 'email' => 'peering@ornek.test',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $recipients = $this->recipients();

        return array_merge([
            'prefixes' => "192.0.2.0/24\n198.18.0.0/22",
            'asn' => 'AS64496',
            'downstream' => '',
            'recipients' => [$recipients['noc']->uuid, $recipients['peering']->uuid],
        ], $overrides);
    }

    public function test_the_form_is_reachable(): void
    {
        $this->actingAs($this->operator())
            ->get(route('announcements.create'))
            ->assertOk()
            ->assertSee('Prefix anons talebi');
    }

    public function test_a_guest_cannot_reach_the_form(): void
    {
        $this->get(route('announcements.create'))->assertRedirect(route('login'));
    }

    public function test_the_preview_shows_one_message_per_recipient(): void
    {
        $this->actingAs($this->operator())
            ->post(route('announcements.preview'), $this->payload())
            ->assertOk()
            ->assertSee('192.0.2.0/24')
            ->assertSee('198.18.0.0/22')
            ->assertSee('AS64496')
            // Türkçe ve İngilizce şablon birlikte üretilir.
            ->assertSee('prefix listenize eklenmesini')
            ->assertSee('add the following prefixes');
    }

    public function test_an_invalid_prefix_is_rejected(): void
    {
        $this->actingAs($this->operator())
            ->post(route('announcements.preview'), $this->payload(['prefixes' => "192.0.2.0/24\nbozuk-blok"]))
            ->assertSessionHasErrors('prefixes');
    }

    public function test_a_prefix_without_a_mask_is_rejected(): void
    {
        $this->actingAs($this->operator())
            ->post(route('announcements.preview'), $this->payload(['prefixes' => '192.0.2.0']))
            ->assertSessionHasErrors('prefixes');
    }

    public function test_an_invalid_asn_is_rejected(): void
    {
        $this->actingAs($this->operator())
            ->post(route('announcements.preview'), $this->payload(['asn' => 'AS-kirk']))
            ->assertSessionHasErrors('asn');
    }

    public function test_at_least_one_recipient_is_required(): void
    {
        $this->actingAs($this->operator())
            ->post(route('announcements.preview'), $this->payload(['recipients' => []]))
            ->assertSessionHasErrors('recipients');
    }

    public function test_an_unknown_recipient_is_rejected(): void
    {
        $this->actingAs($this->operator())
            ->post(route('announcements.preview'), $this->payload(['recipients' => ['00000000-0000-4000-8000-000000000000']]))
            ->assertSessionHasErrors('recipients.0');
    }

    public function test_sending_queues_one_job_per_recipient(): void
    {
        Queue::fake();

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload())
            ->assertRedirect(route('announcements.create'));

        Queue::assertPushed(SendAnnouncementJob::class, 2);
    }

    public function test_sending_is_recorded(): void
    {
        Queue::fake();

        $this->actingAs($this->operator())->post(route('announcements.send'), $this->payload());

        $this->assertDatabaseHas('activity_logs', ['action' => 'announcement_sent']);
    }

    public function test_invalid_input_never_queues_anything(): void
    {
        Queue::fake();

        $this->actingAs($this->operator())
            ->post(route('announcements.send'), $this->payload(['prefixes' => 'bozuk']));

        Queue::assertNothingPushed();
    }

    public function test_the_singular_wording_is_used_for_one_prefix(): void
    {
        $messages = app(AnnouncementService::class)
            ->compose('10.0.0.0/24', 'AS64496', null, array_map(
                fn (AnnouncementRecipient $recipient): string => $recipient->uuid,
                array_values($this->recipients()),
            ));

        $this->assertStringContainsString('bloğun', $messages[0]['body']);
        $this->assertStringContainsString('following prefix ', $messages[1]['body']);
    }

    public function test_the_downstream_line_only_appears_when_given(): void
    {
        $service = app(AnnouncementService::class);

        $this->assertStringNotContainsString(
            'Downstream',
            $service->compose('10.0.0.0/24', 'AS64496', null, [$this->recipients()['noc']->uuid])[0]['body'],
        );

        $this->assertStringContainsString(
            'Downstream AS numarası: AS64512',
            $service->compose('10.0.0.0/24', 'AS64496', 'AS64512', [$this->recipients()['noc']->uuid])[0]['body'],
        );
    }

    public function test_sending_records_one_mail_per_recipient(): void
    {
        Queue::fake();
        $user = $this->operator();

        $this->actingAs($user)->post(route('announcements.send'), $this->payload())->assertRedirect();

        $this->assertDatabaseCount('announcement_mails', 2);

        $noc = AnnouncementMail::query()->firstWhere('recipient_label', 'NOC');

        $this->assertSame('tr', $noc->locale);
        $this->assertSame($user->id, $noc->user_id);
        $this->assertSame(MailStatus::Queued, $noc->status);
        $this->assertStringContainsString('192.0.2.0/24', $noc->body);
        // Türkçe alıcıya Türkçe metin gider.
        $this->assertStringContainsString('Merhaba', $noc->body);

        $peering = AnnouncementMail::query()->firstWhere('recipient_label', 'Peering');

        $this->assertSame('en', $peering->locale);
        $this->assertStringContainsString('Hello', $peering->body);

        /*
         * Kuyruk ADI da doğrulanır. İş kuyruğunu seçmezse "default"a düşer ve
         * onu dinleyen hiçbir işçi yoktur: mail alınır, hiç işlenmez.
         */
        Queue::assertPushedOn(config('announcements.queue'), SendAnnouncementJob::class);
    }

    public function test_the_job_marks_the_record_as_sent(): void
    {
        Mail::fake();
        $record = AnnouncementMail::factory()->create();

        (new SendAnnouncementJob($record->id))->handle();

        $this->assertSame(MailStatus::Sent, $record->fresh()->status);
        $this->assertNotNull($record->fresh()->sent_at);
    }

    public function test_a_failed_job_is_recorded_with_its_error(): void
    {
        $record = AnnouncementMail::factory()->create();

        (new SendAnnouncementJob($record->id))->failed(new RuntimeException('SMTP kapalı'));

        $this->assertSame(MailStatus::Failed, $record->fresh()->status);
        $this->assertSame('SMTP kapalı', $record->fresh()->error);
    }

    public function test_the_history_is_listed_with_the_sent_text(): void
    {
        AnnouncementMail::factory()->sent()->create([
            'recipient_email' => 'noc@ornek.test',
            'prefixes' => '192.0.2.0/24',
            'body' => 'Merhaba, prefix listenize ekleyin.',
        ]);

        $this->actingAs($this->operator())
            ->get(route('announcements.create'))
            ->assertOk()
            ->assertSee('Gönderim geçmişi')
            ->assertSee('noc@ornek.test')
            ->assertSee('Gönderildi')
            ->assertSee('prefix listenize ekleyin');
    }

    public function test_the_history_can_be_filtered(): void
    {
        AnnouncementMail::factory()->create(['recipient_email' => 'noc@ornek.test']);
        AnnouncementMail::factory()->create(['recipient_email' => 'peering@ornek.test']);

        $this->actingAs($this->operator())
            ->get(route('announcements.create', ['q' => 'peering']))
            ->assertOk()
            ->assertSee('peering@ornek.test')
            ->assertDontSee('noc@ornek.test');
    }

    public function test_a_user_without_permission_cannot_reach_or_send(): void
    {
        Queue::fake();
        $yabanci = User::factory()->create();

        $this->actingAs($yabanci)->get(route('announcements.create'))->assertForbidden();
        $this->actingAs($yabanci)->post(route('announcements.preview'), $this->payload())->assertForbidden();
        $this->actingAs($yabanci)->post(route('announcements.send'), $this->payload())->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('announcement_mails', 0);
    }

    public function test_viewing_does_not_grant_sending(): void
    {
        Queue::fake();

        // Yalnızca "görüntüle" yetkisi olan biri metni görebilir ama gönderemez.
        $this->actingAs($this->operator(['view']))
            ->post(route('announcements.send'), $this->payload())
            ->assertForbidden();

        Queue::assertNothingPushed();
    }
}
