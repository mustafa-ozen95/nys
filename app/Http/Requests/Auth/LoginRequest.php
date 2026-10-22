<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'e-posta adresi',
            'password' => 'parola',
        ];
    }

    /**
     * Kimlik bilgilerini doğrular ve oturumu başlatır.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        /*
         * HATIRLAMA ÇEREZİ VERİLMEZ (ikinci parametre false).
         *
         * Kalıcı çerez, hareketsizlik süresini anlamsız kılıyordu: oturum
         * düşse bile kullanıcı sessizce geri içeri alınıyordu. Giriş
         * ekranındaki kutucuk da kaldırıldı — ama karar BURADA duruyor:
         * forma güvenmek denetim değildir, isteği elle kuran "remember=1"
         * yazabilirdi.
         */
        if (! Auth::attempt($this->only('email', 'password'), remember: false)) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'Girilen bilgiler kayıtlarımızla eşleşmiyor.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        $limit = (int) config('auth.max_attempts');

        /*
         * SIFIR "KİLİT YOK" DEMEKTİR ve burada erken dönmek ŞARTTIR.
         *
         * `tooManyAttempts($key, 0)` sınırı kaldırmaz: sayaç sıfırdan büyük
         * ya da eşit olduğu için İLK yanlış paroladan sonra kilitler, yani
         * kapatmak isterken en sert hâline getirirdi.
         */
        if ($limit <= 0) {
            return;
        }

        if (! RateLimiter::tooManyAttempts($this->throttleKey(), $limit)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Çok fazla deneme yapıldı. {$seconds} saniye sonra tekrar deneyin.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
