<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Başlıklar uygulamadan gelir (bkz. App\Http\Middleware\SecurityHeaders);
 * vekil ya da vhost değişse de kaybolmamalı.
 */
final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_carries_the_security_headers(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $response->assertHeader('Strict-Transport-Security');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
    }

    public function test_the_content_security_policy_blocks_inline_and_foreign_sources(): void
    {
        $csp = $this->actingAs(User::factory()->admin()->create())
            ->get(route('overview'))
            ->headers->get('Content-Security-Policy');

        // Satır içi script'e izin verilmiyor: Blade'de zaten yok.
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        // Çerçeveleme korumasını asıl bu üstleniyor; X-Frame-Options onu
        // tanımayan eski tarayıcılar için ayrıca gönderiliyor.
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    /**
     * Başlıkları UYGULAMANIN KENDİSİ gönderir.
     *
     * Önündeki bir nginx katmanının X-Frame-Options ya da Referrer-Policy
     * koyduğu varsayılmaz: imajın /etc/nginx ağacında add_header yoktur;
     * uygulama göndermezse yanıtta bu başlıklar hiç olmaz. "Başka katman
     * zaten koyuyor" varsayımına dayanan bir test, korumanın YOKLUĞUNU
     * sabitler.
     *
     * Genel kural: bir katmanın varlığı yorumla değil ölçümle bilinir.
     */
    public function test_the_headers_are_sent_by_the_application_itself(): void
    {
        $response = $this->get(route('login'));

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('same-origin', $response->headers->get('Referrer-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('none', $response->headers->get('X-Permitted-Cross-Domain-Policies'));
    }
}
