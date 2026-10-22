<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Girişte hesap kilidi — ayardan gelir, sıfır "kilit yok" demektir.
 *
 * Sayı ayardan okunur, koda gömülü değildir; kilit gerekmeyen kurulumlarda
 * sıfırla kapatılabilir.
 *
 * İKİNCİ SINAMA BİRİNCİSİ KADAR ÖNEMLİ: sıfırı "sınırsız" sanıp doğrudan
 * `tooManyAttempts($key, 0)` çağırmak kilidi KALDIRMAZ, İLK yanlış paroladan
 * sonra kilitler — kapatmak isterken en sert hâline getirir.
 */
class LoginLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('kisi@ornek.test|127.0.0.1');
    }

    private function user(): User
    {
        return User::factory()->create([
            'email' => 'kisi@ornek.test',
            'password' => Hash::make('dogru-parola-1234'),
        ]);
    }

    /** @return TestResponse */
    private function try(string $password)
    {
        return $this->post('/login', [
            'email' => 'kisi@ornek.test',
            'password' => $password,
        ]);
    }

    #[Test]
    public function sinir_sifirken_art_arda_yanlis_parola_kilitlemez(): void
    {
        config(['auth.max_attempts' => 0]);

        $this->user();

        for ($i = 0; $i < 12; $i++) {
            $this->try('yanlis')->assertSessionHasErrors('email');
        }

        $this->try('dogru-parola-1234')->assertRedirect();
        $this->assertAuthenticated();
    }

    #[Test]
    public function sinir_verilince_kilit_calisir(): void
    {
        config(['auth.max_attempts' => 3]);

        $this->user();

        for ($i = 0; $i < 3; $i++) {
            $this->try('yanlis');
        }

        /* Sayaç dolduktan sonra DOĞRU parola bile geçmez. */
        $this->try('dogru-parola-1234')->assertInvalid(['email' => 'Çok fazla deneme']);

        $this->assertGuest();
    }
}
