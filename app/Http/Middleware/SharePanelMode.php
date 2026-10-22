<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\PanelMode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panelin ayrıntı düzeyini bütün görünümlere verir.
 *
 * Her ekranın kullanıcıya tek tek sorması gerekmesin diye tek yerden
 * paylaşılır; form alanı bileşeni ($panelMode) doğrudan buradan okur.
 *
 * Oturum açmamış isteklerde (giriş, parola sıfırlama) BASİT varsayılır:
 * o ekranlarda gelişmiş alan yoktur ve görünüm değişkeni tanımsız kalmamalı.
 */
class SharePanelMode
{
    public function handle(Request $request, Closure $next): Response
    {
        View::share('panelMode', $request->user()?->activePanelMode() ?? PanelMode::Simple);
        View::share('gelismisModaGecebilir', (bool) $request->user()?->advancedModeCanPass());

        return $next($request);
    }
}
