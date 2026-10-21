<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Panelin ayrıntı düzeyini değiştirir.
 *
 * Yetkilendirme: kullanıcı yalnızca KENDİ görünüm tercihini değiştirir;
 * kimsenin verisine ve hiçbir cihaza dokunmaz. Rota "auth" altındadır ve
 * hedef her zaman istekte bulunan kullanıcıdır — ayrı bir yetki gerekmez.
 */
class PanelModeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        $hedef = $user->etkinPanelModu()->other();

        /* Gelişmiş moda yalnızca yetkisi olan geçer; düğme gizli olsa da istek elle atılabilir. */
        abort_if($hedef->isAdvanced() && ! $user->gelismisModaGecebilir(), 403, 'Gelişmiş moda geçme yetkiniz yok.');

        $user->update(['panel_mode' => $hedef]);

        return back()->with('status', $user->panel_mode->label().' moda geçildi.');
    }
}
