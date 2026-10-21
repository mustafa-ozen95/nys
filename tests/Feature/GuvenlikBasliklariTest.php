<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tarayıcıya gönderilen güvenlik başlıkları.
 *
 * Başlıkları uygulama gönderir; "önündeki sunucunun nginx ayarı zaten
 * koyuyor" varsayımına güvenilmez — konteynerde öyle bir ayar yoktur ve
 * varsayıma güvenilirse başlıklar hiç gönderilmez. Yorum ölçülmedikçe katman
 * sayılmaz; bu sınama o ölçümü kalıcı kılar.
 */
class GuvenlikBasliklariTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function panel_sayfasi_butun_basliklari_gonderir(): void
    {
        $yanit = $this->get(route('login'));

        $yanit->assertOk();
        $yanit->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $yanit->assertHeader('X-Content-Type-Options', 'nosniff');
        $yanit->assertHeader('Referrer-Policy', 'same-origin');
        $yanit->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
        /*
         * HSTS SIFIR: sertifika düşerse tarayıcı uyarı ekranını bile
         * göstermiyor ve panele girilemiyor (bkz. HstsKapaliTest).
         */
        $yanit->assertHeader('Strict-Transport-Security', 'max-age=0');

        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            (string) $yanit->headers->get('Content-Security-Policy'),
        );
    }
}
