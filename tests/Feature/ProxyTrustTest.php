<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * TERS VEKİL ARKASINDA ŞEMA DOĞRU OKUNMALI.
 *
 * Konteyner kurulumunda TLS önde duran vekilde sonlanıyor ve uygulamaya düz
 * HTTP geliyor. Güven tanımlı değilse istek "http" sanılır; üretilen mutlak
 * adresler http olur, Secure çerez gönderilmez ve OAuth dönüş adresi kayıtlı
 * adresle eşleşmediği için takas reddedilir.
 *
 * İkinci sınanan da en az birincisi kadar önemli: güven HERKESE VERİLMEZ.
 * X-Forwarded-For'a koşulsuz güvenmek onu yazılabilir kılardı ve istemci
 * adresine bakan hız sınırları ile denetim kayıtları atlatılabilirdi.
 */
final class ProxyTrustTest extends TestCase
{
    public function test_guvenilen_vekilin_sema_basligi_dikkate_alinir(): void
    {
        $request = Request::create('http://panel.ornek.test/', server: [
            'REMOTE_ADDR' => '172.18.0.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->assertTrue($this->parse($request)->isSecure());
    }

    public function test_yabanci_adresin_basligi_yok_sayilir(): void
    {
        $request = Request::create('http://panel.ornek.test/', server: [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '10.1.2.3',
        ]);

        $resolved = $this->parse($request);

        $this->assertFalse($resolved->isSecure(), 'Dışarıdan gelen şema başlığına güvenilmemeli.');
        $this->assertSame('203.0.113.7', $resolved->ip(), 'İstemci kendi adresini yazamamalı.');
    }

    /** Ara katman yığınını gerçekten koştur: kural bootstrap/app.php'de duruyor. */
    private function parse(Request $request): Request
    {
        $this->app->instance('request', $request);

        $this->app->make(Kernel::class)
            ->handle($request);

        return $request;
    }
}
