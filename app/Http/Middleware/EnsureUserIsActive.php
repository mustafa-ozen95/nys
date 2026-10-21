<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pasif veya engellenmiş hesapların oturumunu anında sonlandırır.
 *
 * Kural her web isteğini kapsar (bkz. bootstrap/app.php). Tek istisna proxy
 * bekçisi: onu nginx `auth_request` çağırıyor ve nginx yalnızca 2xx/401/403
 * anlıyor — buradan dönen yönlendirmeyi "hata" sayıp 500 üretirdi. O uç
 * hesabın durumunu kendisi soruyor ve 403 dönüyor
 * (bkz. RemoteProxyGuardController).
 */
class EnsureUserIsActive
{
    /** Yönlendirme anlamayan uçlar. */
    private const ATLANAN = ['ajax.remote.guard'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (in_array($request->route()?->getName(), self::ATLANAN, true)) {
            return $next($request);
        }

        if ($user !== null && ! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Hesabınız etkin değil. Lütfen yöneticinizle görüşün.']);
        }

        return $next($request);
    }
}
