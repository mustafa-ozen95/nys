<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ActivityChannel;
use App\Services\Activity\ActivityChain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kimliği doğrulanmış her isteği kayda geçirir.
 *
 * Controller'lardaki ayrıntılı kayıtlar (hangi switche ne yazıldı) yerini
 * korur; bu ara katman onların ARASINDAKİ boşluğu doldurur: hangi kullanıcı
 * hangi ekranı ne zaman açtı, hangi formu gönderdi, sonuç ne oldu. Denetim
 * sorusu "değişikliği kim yaptı" kadar "kim neye baktı"dır da.
 *
 * Kayıt terminate aşamasında yazılır: yanıt kullanıcıya gittikten sonra
 * çalışır, sayfayı bekletmez.
 */
class RecordUserActivity
{
    /**
     * Kaydedilmeyen rotalar.
     *
     * ajax.remote.guard nginx'in auth_request ile HER proxy isteğinde
     * sorduğu bekçi ucudur; bir terminal oturumu boyunca yüzlerce kez
     * çağrılır ve kaydı gerçek hareketleri görünmez ederdi. Oturumun
     * açılışı ve kapanışı zaten ayrıca kaydediliyor.
     */
    private const IGNORED = [
        'ajax.remote.guard',
        'ajax.remote.keep-alive',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if ($user === null || in_array($request->route()?->getName(), self::IGNORED, true)) {
            return;
        }

        app(ActivityChain::class)->append([
            'user_id' => $user->id,
            'channel' => ActivityChannel::Access,
            'action' => strtolower($request->method()),
            'description' => $this->describe($request, $response),
            'ip_address' => $request->ip(),
        ]);
    }

    /**
     * "GET /switches/... · switches.ports · 200"
     *
     * Yol ham hâliyle yazılır; rota adı ise ekranın adıdır ve arama bunun
     * üzerinden yapılır.
     */
    private function describe(Request $request, Response $response): string
    {
        return implode(' · ', array_filter([
            $request->method().' /'.ltrim($request->path(), '/'),
            $request->route()?->getName(),
            (string) $response->getStatusCode(),
        ]));
    }
}
