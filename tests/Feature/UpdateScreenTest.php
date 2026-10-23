<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
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

    private const HOOK = 'http://10.0.0.9:3000/v1/deploy-hooks/GIZLI_HOOK_JETONU';

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

    private function yeniSurumBulunmus(User $yonetici): void
    {
        $this->actingAs($yonetici)->post(route('settings.updates.check'));
    }

    public function test_guncelle_dugmesi_yalnizca_yeni_surum_ve_hook_varken_gorunur(): void
    {
        $yonetici = User::factory()->admin()->create();

        // Hook var ama henüz kontrol yok: düğme yok.
        config(['update.hook' => self::HOOK]);
        $this->actingAs($yonetici)->get(route('settings.updates'))->assertDontSee(route('settings.updates.apply'));

        // Yeni sürüm var, hook yok: düğme yok, ne gerektiği yazılı.
        config(['update.hook' => null]);
        $this->yeniSurumBulunmus($yonetici);
        $this->actingAs($yonetici)->get(route('settings.updates'))
            ->assertDontSee(route('settings.updates.apply'))
            ->assertSee('UPDATE_HOOK_URL');

        // İkisi de var: düğme görünür, hook adresi görünmez.
        config(['update.hook' => self::HOOK]);
        $this->actingAs($yonetici)->get(route('settings.updates'))
            ->assertSee(route('settings.updates.apply'))
            ->assertDontSee('GIZLI_HOOK_JETONU');

        // Panel güncellenince (çalışan = son derleme) düğme kaybolur.
        config(['update.version' => self::LAST]);
        $this->actingAs($yonetici)->get(route('settings.updates'))->assertDontSee(route('settings.updates.apply'));
    }

    public function test_guncelle_hooku_cagirir_ve_kayda_gecer(): void
    {
        Http::fake(['10.0.0.9:3000/*' => Http::response(['deploymentId' => 77], 202)]);
        config(['update.hook' => self::HOOK]);
        $yonetici = User::factory()->admin()->create();
        $this->yeniSurumBulunmus($yonetici);

        $this->actingAs($yonetici)->from(route('settings.updates'))
            ->post(route('settings.updates.apply'))
            ->assertRedirect(route('settings.updates'))
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'deploy #77'));

        Http::assertSent(fn ($istek): bool => $istek->url() === self::HOOK && $istek->method() === 'POST');
        $this->assertSame(1, ActivityLog::where('action', 'guncelleme_baslat')->count());
        $this->assertStringNotContainsString('GIZLI_HOOK_JETONU', (string) ActivityLog::where('action', 'guncelleme_baslat')->value('description'));
    }

    /** Düğme gizli olsa da istek elle gönderilebilir: karar sunucuda. */
    public function test_yeni_surum_yoksa_hook_cagrilmaz(): void
    {
        config(['update.hook' => self::HOOK, 'update.version' => self::LAST]);
        $yonetici = User::factory()->admin()->create();
        $this->yeniSurumBulunmus($yonetici);

        $this->actingAs($yonetici)->from(route('settings.updates'))
            ->post(route('settings.updates.apply'))
            ->assertSessionHas('error');

        Http::assertNotSent(fn ($istek): bool => str_contains($istek->url(), 'deploy-hooks'));
        $this->assertSame(0, ActivityLog::where('action', 'guncelleme_baslat')->count());
    }

    public function test_yonetici_olmayan_guncelleyemez(): void
    {
        config(['update.hook' => self::HOOK]);
        $this->yeniSurumBulunmus(User::factory()->admin()->create());

        $this->actingAs(User::factory()->create())
            ->post(route('settings.updates.apply'))
            ->assertForbidden();

        Http::assertNotSent(fn ($istek): bool => str_contains($istek->url(), 'deploy-hooks'));
    }

    public function test_platform_reddederse_sebep_gorunur_hook_gorunmez(): void
    {
        Http::fake(['10.0.0.9:3000/*' => Http::response(['message' => 'not found'], 404)]);
        config(['update.hook' => self::HOOK]);
        $yonetici = User::factory()->admin()->create();
        $this->yeniSurumBulunmus($yonetici);

        $this->actingAs($yonetici)->from(route('settings.updates'))
            ->post(route('settings.updates.apply'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, '404') && ! str_contains($m, 'GIZLI_HOOK_JETONU'));
    }
}
