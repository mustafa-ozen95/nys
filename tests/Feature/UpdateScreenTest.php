<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Update\UpdateCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ayarlar → Güncelleme ve yöneticinin uyarı şeridi.
 *
 * Asıl kurallar: ekran ve şerit yalnız yöneticide, düğme kontrolü hemen
 * yapar, jeton sayfada görünmez, panel güncellenince eski "yeni sürüm var"
 * sonucu susar.
 */
class UpdateScreenTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'github_pat_SINAMA_JETONU_123';

    private const RUNNING = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const LAST = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/guncelleme-'.bin2hex(random_bytes(4));
        $this->instance(UpdateCheck::class, new UpdateCheck($this->directory));
        config([
            'update.repo' => 'mustafa-ozen95/ornek',
            'update.token' => self::TOKEN,
            'update.version' => self::RUNNING,
        ]);

        Http::fake([
            'api.github.com/*/runs*' => Http::response(['workflow_runs' => [['head_sha' => self::LAST, 'updated_at' => '2026-10-20T10:00:00Z']]]),
            'api.github.com/*/compare/*' => Http::response(['status' => 'ahead', 'ahead_by' => 3, 'behind_by' => 0, 'commits' => [
                ['sha' => self::LAST, 'commit' => ['message' => 'Yeni özellik: güncelleme sekmesi', 'committer' => ['date' => '2026-10-20T09:00:00Z']]],
            ]]),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/state.json');
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_ekran_yalnizca_yoneticide_acilir(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.updates'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('settings.updates.check'))
            ->assertForbidden();

        // Ayar bölümleri devredilebilir; güncelleme ekranı devredilmez.
        $this->actingAs(User::factory()->network()->create())
            ->get(route('settings.updates'))
            ->assertForbidden();
        Http::assertNothingSent();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.updates'))
            ->assertOk()
            ->assertSee('aaaaaaa')
            ->assertSee('henüz yapılmadı')
            ->assertSee('Şimdi kontrol et');
    }

    public function test_dugme_kontrol_eder_commitler_gorunur_jeton_gorunmez(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('settings.updates'))
            ->post(route('settings.updates.check'))
            ->assertRedirect(route('settings.updates'))
            ->assertSessionHas('status');

        $this->actingAs($admin)
            ->get(route('settings.updates'))
            ->assertOk()
            ->assertSee('yeni sürüm var')
            ->assertSee('Yeni özellik: güncelleme sekmesi')
            ->assertSee('bbbbbbb')
            ->assertSee('ve 2 commit daha.')
            ->assertSee($admin->name)
            ->assertDontSee(self::TOKEN);
    }

    public function test_yeni_surum_uyarisi_yalnizca_yoneticide_ve_guncellenince_susar(): void
    {
        app(UpdateCheck::class)->check('günlük tur');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('account.profile'))
            ->assertOk()
            ->assertSee('Panelin yeni sürümü var.')
            ->assertSee('3 commit geride');

        $this->actingAs(User::factory()->create())
            ->get(route('account.profile'))
            ->assertOk()
            ->assertDontSee('Panelin yeni sürümü var.');

        config(['update.version' => self::LAST]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('account.profile'))
            ->assertOk()
            ->assertDontSee('Panelin yeni sürümü var.');
    }

    public function test_kontrol_her_sabah_zamanlanmis(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'update:check'));

        $this->assertNotNull($event, 'update:check zamanlayıcıda yok.');
        $this->assertSame('50 6 * * *', $event->expression);
    }

    public function test_kontrol_edilemezse_sebep_gorunur(): void
    {
        // Testler ağa çıkmaz: GitHub'a ulaşılamıyormuş gibi.
        Http::fake(fn () => throw new ConnectionException('ağ kapalı'));
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('settings.updates'))
            ->post(route('settings.updates.check'))
            ->assertSessionHas('error');

        $this->actingAs($admin)
            ->get(route('settings.updates'))
            ->assertSee('kontrol edilemedi')
            ->assertSee('erişilemedi');
    }
}
