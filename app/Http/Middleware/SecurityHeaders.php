<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Güvenlik başlıkları.
 *
 * nginx'te değil uygulamada durur: vhost değişince başlıkların kaybolmaması
 * için. Değerler config/security.php içindedir.
 *
 * VAR OLAN BAŞLIK EZİLMEZ: bir yanıt kendi kararını yazdıysa (örneğin bir uç
 * bilerek `no-referrer` diyorsa) burada gevşetmek, kararı sessizce geri almak
 * olurdu.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (config('security.headers') as $header => $value) {
            if (filled($value) && ! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        $this->onbellekKapat($request, $response);

        return $response;
    }

    /**
     * OTURUM AÇIK SAYFALAR DİSKE YAZILMAZ.
     *
     * İki sebep, ikisi de gerçek:
     *
     * 1. GÜVENLİK. Sayfalarda kullanıcı adları, adresler ve "göster"e
     *    basıldığında cihaz parolasının kendisi duruyor.
     *    `no-cache` tarayıcıya "kullanmadan önce sor" der ama KOPYAYI
     *    DİSKTEN SİLMEZ; ortak bir makinede çıkış yapıldıktan sonra da o
     *    dosya orada kalır. `no-store` hiç yazmamasını söyler.
     *
     * 2. ESKİ SAYFA. `no-cache` geri/ileri önbelleğini (bfcache) kapatmaz:
     *    kullanıcı geri gidip geldiğinde tarayıcı sayfayı SUNUCUYA HİÇ
     *    SORMADAN bellekten çizer. Panelde bir düğme eklendiğinde kullanıcı
     *    onu göremez; sunucu yeni sayfayı üretmiş olsa da eski kopya
     *    gösterilir. `no-store` bu yolu da kapatır.
     *
     * YALNIZCA HTML ve yalnızca OTURUM AÇIKKEN: varlıklar (css/js) uzun
     * ömürlü önbelleğe muhtaç, giriş sayfasının da saklanacak bir şeyi yok.
     *
     * Var olan başlık yine ezilmez (bkz. yukarısı): parola ucu kendi
     * `no-store`unu zaten yazıyor.
     */
    private function onbellekKapat(Request $request, Response $response): void
    {
        /*
         * "Zaten var mı" diye BAKILMAZ: Laravel oturumlu her yanıta
         * kendiliğinden `no-cache, private` yazıyor, yani başlık her zaman
         * dolu olurdu ve bu kural hiç çalışmazdı. Bakılacak olan, yanıtın
         * BİLEREK no-store demiş olmasıdır — o zaman dokunulmaz.
         */
        if ($request->user() === null) {
            return;
        }

        if (str_contains((string) $response->headers->get('Cache-Control', ''), 'no-store')) {
            return;
        }

        $tur = (string) $response->headers->get('Content-Type', '');

        if (! str_contains($tur, 'text/html')) {
            return;
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
    }
}
