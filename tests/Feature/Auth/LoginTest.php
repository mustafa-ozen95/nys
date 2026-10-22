<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\ActivityChannel;
use App\Models\ActivityLog;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Activity\ActivityChain;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_is_reachable_by_guests(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Oturum aç')
            /*
             * Oturumu açık tutma kutucuğu YOKTUR: kalıcı hatırlama çerezi
             * hareketsizlik süresini anlamsız kılar.
             */
            ->assertDontSee('Bu cihazda oturumu açık tut')
            ->assertDontSee('Bu switchte');
    }

    public function test_an_active_user_can_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'ali@example.test']);

        $this->post(route('login'), [
            'email' => 'ali@example.test',
            'password' => 'password',
        ])->assertRedirect(route('overview'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_signing_in_is_recorded_in_the_activity_log(): void
    {
        User::factory()->create(['email' => 'ali@example.test']);

        $this->post(route('login'), ['email' => 'ali@example.test', 'password' => 'password']);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'login',
            'channel' => ActivityChannel::Auth->value,
        ]);
    }

    public function test_wrong_credentials_are_rejected_and_recorded(): void
    {
        User::factory()->create(['email' => 'ali@example.test']);

        $this->post(route('login'), [
            'email' => 'ali@example.test',
            'password' => 'yanlis-parola',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('activity_logs', ['action' => 'login_failed']);
    }

    /**
     * Oturum olayları hash zincirine girer; DB'ye yazabilen biri
     * giriş/çıkış kaydını fark edilmeden değiştiremez.
     */
    public function test_oturum_olaylari_hash_zincirine_girer(): void
    {
        $user = User::factory()->create(['password' => Hash::make('dogru-parola-1')]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'dogru-parola-1']);
        $this->assertAuthenticated();

        $record = ActivityLog::query()->where('action', 'login')->firstOrFail();
        $this->assertNotNull($record->hash);
        $this->assertTrue(app(ActivityChain::class)->verify()['ok']);

        DB::table('activity_logs')->where('id', $record->id)->update(['description' => 'değiştirildi']);

        $this->assertSame($record->id, app(ActivityChain::class)->verify()['broken_id']);
    }

    /**
     * Tek IP'den farklı hesaplara yapılan deneme tek satıra düşmemeli;
     * aynı hesaba tekrar tekrar denemeler ise yine tek satır.
     */
    public function test_basarisiz_giris_tekillestirmesi_denenen_hesabi_ayirir(): void
    {
        foreach (['yem@example.test', 'kurban1@example.test', 'kurban2@example.test', 'KURBAN1@example.test'] as $email) {
            $this->post(route('login'), ['email' => $email, 'password' => 'yanlis-parola']);
        }

        $records = ActivityLog::query()->where('action', 'login_failed')->pluck('description')->all();

        $this->assertCount(3, $records);
        $this->assertContains('Başarısız giriş denemesi: kurban2@example.test', $records);
    }

    public function test_an_inactive_user_is_signed_out_on_the_next_request(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)
            ->get(route('overview'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_banned_user_is_signed_out_on_the_next_request(): void
    {
        $this->actingAs(User::factory()->banned()->create())
            ->get(route('overview'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * Kilit AYARDAN gelir ve varsayılanı KAPALIDIR.
     *
     * Varsayılan kapalı olduğu için sınama kilidi AÇARAK çalıştığını
     * doğrular. Kapalıyken kilitlemediği ayrıca sınanır (bkz.
     * LoginLockTest).
     */
    public function test_repeated_failures_are_rate_limited(): void
    {
        config(['auth.max_attempts' => 5]);

        User::factory()->create(['email' => 'ali@example.test']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login'), ['email' => 'ali@example.test', 'password' => 'hatali']);
        }

        $this->post(route('login'), ['email' => 'ali@example.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('activity_logs', ['action' => 'lockout']);

        RateLimiter::clear(mb_strtolower('ali@example.test').'|127.0.0.1');
    }

    public function test_guests_are_redirected_away_from_the_panel(): void
    {
        $this->get(route('overview'))->assertRedirect(route('login'));
    }

    public function test_a_signed_in_user_can_sign_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('activity_logs', ['action' => 'logout']);
    }

    public function test_the_overview_only_lists_switches_the_user_may_see(): void
    {
        // Genel bakış trafik için Zabbix'e gider; bu test onu sınamıyor.
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'result' => [], 'id' => 1])]);

        $user = User::factory()->create();
        $visible = NetworkSwitch::factory()->create(['hostname' => 'GORUNUR-01']);
        NetworkSwitch::factory()->create(['hostname' => 'GIZLI-01']);

        app(UserPermissionResolver::class)
            ->syncResource($user, 'switches', [], ['view' => [$visible->id]]);

        $this->actingAs($user->fresh())
            ->get(route('overview'))
            ->assertOk()
            ->assertSee('GORUNUR-01')
            ->assertDontSee('GIZLI-01');
    }
}
