<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Identity\AccountMatcher;
use App\Services\Identity\SharedLogout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ORTAK ÇIKIŞ — panel tarafı: bir panelden çıkış merkezdeki (KYM) ve
 * öteki panellerdeki oturumu da kapatır.
 *
 * Merkezdeki son çıkıştan önce açılmış oturum kapanır; an bildirimle, durum
 * sorgusuyla ya da bu panelden çıkışla gelir. Bu panelden çıkış merkeze de
 * bildirilir.
 */
final class SharedLogoutTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sinama-bildirim-sirri';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'identity.acik' => true,
            'identity.configured' => true,
            'identity.url' => 'https://merkez.ornek.test',
            'identity.client_id' => 'istemci',
            'identity.client_secret' => 'oauth-sirri',
            'identity.notify_secret' => self::SECRET,
        ]);
    }

    private function bag(): UserIdentity
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);

        return UserIdentity::create([
            'user_id' => $user->id,
            'provider' => AccountMatcher::PROVIDER,
            'subject' => (string) Str::uuid(),
            'email' => $user->email,
        ]);
    }

    private function exitNotify(string $subject, int $moment, ?string $signature = null): TestResponse
    {
        return $this->withHeaders(['X-Identity-Signature' => $signature ?? hash_hmac('sha256', 'logout|'.$subject.'|'.$moment, self::SECRET)])
            ->postJson(route('api.identity.logout'), ['subject' => $subject, 'time' => $moment]);
    }

    #[Test]
    public function imzali_cikis_bildirimi_ani_yazar_eskisi_geri_almaz(): void
    {
        $bag = $this->bag();

        $this->exitNotify($bag->subject, 5_000, 'yanlis')->assertOk();
        $this->assertNull($bag->fresh()->merkez_cikis_ms, 'imzasız bildirim uygulanmaz');

        /* İmza anı da kapsar: başka anla gönderilen imza tutmaz. */
        $this->withHeaders(['X-Identity-Signature' => hash_hmac('sha256', 'logout|'.$bag->subject.'|1', self::SECRET)])
            ->postJson(route('api.identity.logout'), ['subject' => $bag->subject, 'time' => 9_999])->assertOk();
        $this->assertNull($bag->fresh()->merkez_cikis_ms);

        $this->exitNotify($bag->subject, 5_000)->assertOk();
        $this->assertSame(5_000, $bag->fresh()->merkez_cikis_ms);

        $this->exitNotify($bag->subject, 4_000)->assertOk();
        $this->assertSame(5_000, $bag->fresh()->merkez_cikis_ms, 'geç gelen eski an yeni girişi kapatmaz');
    }

    #[Test]
    public function cikistan_once_acilan_oturum_kapanir_sonraki_kalir(): void
    {
        $bag = $this->bag();
        $bag->forceFill(['merkez_cikis_ms' => 2_000])->save();
        Cache::put('kimlik:dogrulama:'.$bag->user_id, true, 60);

        $this->actingAs($bag->user)->withSession([SharedLogout::SESSION_KEY => 1_000])
            ->get(route('overview'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($bag->user)->withSession([SharedLogout::SESSION_KEY => 3_000])
            ->get(route('overview'))
            ->assertOk();
    }

    #[Test]
    public function giris_oturumun_acilis_anini_yazar(): void
    {
        $bag = $this->bag();
        $once = SharedLogout::nowMs();

        $this->get('/');
        Auth::login($bag->user);

        $this->assertGreaterThanOrEqual($once, (int) session(SharedLogout::SESSION_KEY));
    }

    #[Test]
    public function panelden_cikis_merkeze_bildirilir(): void
    {
        Http::fake(['*/api/session-closed' => Http::response(['result' => 'received'])]);
        $bag = $this->bag();

        $this->actingAs($bag->user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertGreaterThan(0, (int) $bag->fresh()->merkez_cikis_ms, 'bu paneldeki öteki oturumlar da kapanır');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://merkez.ornek.test/api/session-closed'
            && $request['subject'] === $bag->subject && $request['client_id'] === 'istemci');
    }

    #[Test]
    public function merkez_yanit_vermese_de_cikis_olur(): void
    {
        Http::fake(['*/api/session-closed' => Http::response('', 503)]);
        $bag = $this->bag();

        $this->actingAs($bag->user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function durum_sorgusundaki_son_cikis_uygulanir(): void
    {
        $bag = $this->bag();
        Http::fake(['*/api/identity-status' => Http::response(['identities' => [$bag->subject => [
            'status' => 'etkin', 'can_enter' => true, 'role' => null, 'last_logout' => 7_000,
        ]]])]);

        $this->withHeaders(['X-Identity-Signature' => hash_hmac('sha256', $bag->subject, self::SECRET)])
            ->postJson(route('api.identity.refresh'), ['subject' => $bag->subject])->assertOk();

        $this->assertSame(7_000, $bag->fresh()->merkez_cikis_ms);
    }

    /** Ekran kilidinin çıkışı (süre doldu) yalnız bu oturumu kapatır; merkeze bildirilmez. */
    #[Test]
    public function zaman_asimi_cikisi_ortak_degildir(): void
    {
        Http::fake();
        $bag = $this->bag();

        $this->actingAs($bag->user)->withHeaders(['X-Oturum-Zaman-Asimi' => '1'])
            ->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($bag->fresh()->merkez_cikis_ms);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/api/session-closed'));
    }
}
