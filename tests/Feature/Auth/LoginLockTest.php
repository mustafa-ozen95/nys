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

    private function kullanici(): User
    {
        return User::factory()->create([
            'email' => 'kisi@ornek.test',
            'password' => Hash::make('dogru-parola-1234'),
        ]);
    }

    /** @return TestResponse */
    private function dene(string $parola)
    {
        return $this->post('/giris', [
            'email' => 'kisi@ornek.test',
            'password' => $parola,
        ]);
    }

    #[Test]
    public function sinir_sifirken_art_arda_yanlis_parola_kilitlemez(): void
    {
        config(['auth.max_attempts' => 0]);

        $this->kullanici();

        for ($i = 0; $i < 12; $i++) {
            $this->dene('yanlis')->assertSessionHasErrors('email');
        }

        $this->dene('dogru-parola-1234')->assertRedirect();
        $this->assertAuthenticated();
    }

    #[Test]
    public function sinir_verilince_kilit_calisir(): void
    {
        config(['auth.max_attempts' => 3]);

        $this->kullanici();

        for ($i = 0; $i < 3; $i++) {
            $this->dene('yanlis');
        }

        /* Sayaç dolduktan sonra DOĞRU parola bile geçmez. */
        $this->dene('dogru-parola-1234')->assertInvalid(['email' => 'Çok fazla deneme']);

        $this->assertGuest();
    }
}
