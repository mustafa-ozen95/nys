<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NetworkSwitch;
use App\Services\Switching\SwitchNavigationService;
use App\Services\Switching\SwitchViewService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Switchin KENDİ ayarları: SNMP adı ve yapılandırma yedeği.
 *
 * Bunlar bir nesne listesi değil, cihaza ait tekil ayarlardır. Tek ekranda
 * toplanmaları hem bulunmalarını kolaylaştırır hem de yeni bir cihaz ayarı
 * eklenecek yeri belirsiz bırakmaz.
 *
 * Yazma uçları kendi controller'larındadır (SNMP adı, arşiv); burası yalnızca
 * onları tek yerde toplayan ekrandır.
 */
class SwitchSettingsController extends Controller implements HasMiddleware
{
    public function __construct(
        private SwitchNavigationService $navigation,
        private SwitchViewService $viewData,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        // Ekranın tamamı cihaza yazma eylemlerinden oluşur; okuma yetkisi
        // olan ama yazamayan birine gösterecek bir şeyi yoktur.
        return [new Middleware('can:configure,switch')];
    }

    public function __invoke(Request $request, NetworkSwitch $switch): View
    {
        return view('switches.settings', [
            'switch' => $switch,
            ...$this->viewData->settings($switch),
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'settings'),
        ]);
    }
}
