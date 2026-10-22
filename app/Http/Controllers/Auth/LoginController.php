<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Identity\CenterClient;
use App\Services\Identity\SharedLogout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Yetkilendirme: giriş akışı; guest ara katmanı altındadır.
 */
class LoginController extends Controller
{
    /**
     * Giriş ekranı.
     *
     * Merkezin açık olup olmadığı KARAR olarak buradan geçer; Blade config
     * okumaz. Soruyu `CenterClient` yanıtlar: ayar tek başına yetmiyor,
     * istemci bilgileri de gerekiyor.
     */
    public function create(CenterClient $center): View
    {
        return view('auth.login', [
            'merkezAcik' => $center->isOpen(),
            'merkezAd' => config('identity.name'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        return redirect()->intended(route('overview'));
    }

    /**
     * ORTAK ÇIKIŞ: merkezden giren kişinin çıkışı merkeze bildirilir; merkez
     * kendi oturumunu ve öteki panelleri kapatır (bkz. SharedLogout). Bu
     * paneldeki öteki oturumları da (başka cihaz) aynı an kapatır.
     *
     * ZAMAN AŞIMI ORTAK DEĞİL: ekran kilidinin çıkışı (süre doldu) yalnız bu
     * oturumu kapatır; bir sekmede süresi dolan kişi öteki panellerden atılmaz.
     */
    public function destroy(Request $request, CenterClient $center): RedirectResponse
    {
        $bag = $request->user() !== null && ! SharedLogout::isTimedOut($request) ? SharedLogout::bag($request->user()) : null;

        if ($bag !== null) {
            SharedLogout::isle($bag, SharedLogout::nowMs());

            if ($center->isOpen()) {
                rescue(fn () => $center->sessionClosed($bag->subject));
            }
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
