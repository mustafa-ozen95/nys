<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Identity\AccountMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Merkezin "bu kimlik değişti" bildirimi.
 *
 * Uç OTURUM TAŞIMAZ: çağıran bir insan değil, merkez. Güvenliği iki katmanlı:
 * imza (bildirim sırrıyla HMAC) ve gelen gövdeye hiç güvenmemek — yeni rol ya
 * da durum gövdeden okunmaz, panel doğruyu merkezden kendi sorar.
 */
final class IdentityRefreshEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sinama-bildirim-sirri';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'identity.acik' => true,
            /* Ayar env()'den hesaplanıyor; testte elle açılır. */
            'identity.configured' => true,
            'identity.url' => 'https://merkez.ornek.test',
            'identity.client_id' => 'istemci',
            'identity.client_secret' => 'oauth-sirri',
            'identity.notify_secret' => self::SECRET,
        ]);
    }

    private function identity(UserStatus $status = UserStatus::Active): UserIdentity
    {
        $user = User::factory()->create(['status' => $status]);

        return UserIdentity::create([
            'user_id' => $user->id,
            'provider' => AccountMatcher::PROVIDER,
            'subject' => (string) Str::uuid(),
            'email' => $user->email,
        ]);
    }

    /** @param  array<string, mixed>  $durum */
    private function centerSays(string $subject, array $status): void
    {
        Http::fake(['*/api/identity-status' => Http::response(['identities' => [$subject => $status]])]);
    }

    private function notify(string $subject, ?string $signature = null): TestResponse
    {
        return $this->withHeaders(['X-Identity-Signature' => $signature ?? hash_hmac('sha256', $subject, self::SECRET)])
            ->postJson(route('api.identity.refresh'), ['subject' => $subject]);
    }

    #[Test]
    public function imzali_bildirim_merkezdeki_durumu_uygular(): void
    {
        $bag = $this->identity(UserStatus::Inactive);
        $this->centerSays($bag->subject, ['status' => 'etkin', 'can_enter' => true, 'role' => null]);

        $this->notify($bag->subject)->assertOk();

        $this->assertSame(UserStatus::Active, $bag->user->fresh()->status);
    }

    #[Test]
    public function imzasiz_bildirim_hicbir_sey_yapmaz(): void
    {
        Http::fake();

        $bag = $this->identity(UserStatus::Inactive);

        $this->notify($bag->subject, 'yanlis-imza')->assertOk();

        $this->assertSame(UserStatus::Inactive, $bag->user->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * SIR TANIMSIZSA bildirim kabul edilmez.
     *
     * İmzasız çalışan bir uç, ayarın eksik olduğunu kimseye söylemeden açık
     * kalırdı. O panel değişikliği beş dakikalık turla öğrenir.
     */
    #[Test]
    public function sir_tanimsizken_bildirim_kabul_edilmez(): void
    {
        config(['identity.notify_secret' => null]);
        Http::fake();

        $bag = $this->identity(UserStatus::Inactive);

        $this->notify($bag->subject, hash_hmac('sha256', $bag->subject, ''))->assertOk();

        $this->assertSame(UserStatus::Inactive, $bag->user->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * YEREL KARŞILIĞI OLMAYAN kimlik için merkeze GİDİLMEZ.
     *
     * Bildirim bütün panellere gidiyor ve her panelde her kullanıcının hesabı
     * olmak zorunda değil; bilinmeyen kimlik için merkeze soru sormak, bu ucu
     * merkeze yük bindiren bir kaldıraca çevirirdi.
     */
    #[Test]
    public function bilinmeyen_kimlik_icin_merkeze_sorulmaz(): void
    {
        Http::fake();

        $this->notify((string) Str::uuid())->assertOk();

        Http::assertNothingSent();
    }

    /** Yanıt HER ZAMAN aynı: "bu kimlik burada var mı" sorusu buradan öğrenilemez. */
    #[Test]
    public function response_info_leaks_not(): void
    {
        $bag = $this->identity();
        $this->centerSays($bag->subject, ['status' => 'etkin', 'can_enter' => true, 'role' => null]);

        $existing = $this->notify($bag->subject)->getContent();

        Http::fake();
        $non = $this->notify((string) Str::uuid())->getContent();
        $unsigned = $this->notify($bag->subject, 'yanlis')->getContent();

        $this->assertSame($existing, $non);
        $this->assertSame($existing, $unsigned);
    }
}
