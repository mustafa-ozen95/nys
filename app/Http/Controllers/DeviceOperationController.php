<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DeviceOperationFilterRequest;
use App\Models\ActivityLog;
use App\Models\DeviceOperation;
use App\Models\NetworkSwitch;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Cihaz işlem kayıtları — "cihaz ne dedi" ekranı.
 *
 * Etkinlik kaydından ayrı durur: orası "kim ne yaptı", burası cihazın
 * cevabıdır. Başarısız yazmalar, cihazın uyarıları, okunamayan eşitlemeler ve
 * şema sondasının düşmesi buraya yazılır.
 *
 * Yetki etkinlik kaydıyla AYNI kaynaktan gelir ("logs"): ikisi de aynı
 * denetim bilgisini gösteriyor ve ayrı bir yetki tanımlamak, birini görüp
 * diğerini görememek gibi anlamsız bir durum üretirdi.
 */
class DeviceOperationController extends Controller implements HasMiddleware
{
    public function __construct(private SwitchNavigationService $navigation) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:viewAny,'.ActivityLog::class)];
    }

    public function __invoke(DeviceOperationFilterRequest $request): View
    {
        $filters = $request->filters();

        return view('logs.device-operations', [
            /* Süzme veritabanında: satır bir cihaza ait, yetki de onun. */
            'operations' => DeviceOperation::query()
                ->visibleTo($request->user())
                ->with(['user', 'switch'])
                ->filtered($filters)
                ->latest('id')
                ->paginate(config('ui.per_page'))
                ->withQueryString(),
            'filters' => $filters,
            'statuses' => [
                '' => 'Tüm sonuçlar',
                'failed' => 'Başarısız',
                'warning' => 'Uyarı',
                'ok' => 'Başarılı',
            ],
            'operations_list' => [
                '' => 'Tüm işlemler',
                'write' => 'Cihaza yazma',
                'rollback' => 'Geri alma',
                'push' => 'Config yükleme',
                'sync' => 'Eşitleme',
                'measure' => 'Ölçüm',
                'probe' => 'Şema sondası',
                'rotate' => 'Parola turu',
                'read' => 'Okuma',
            ],
            /*
             * Süzgeç listesi de süzülür: envanter adları "var ama
             * göremezsin" bilgisidir.
             */
            'switches' => NetworkSwitch::query()
                ->visibleTo($request->user())
                ->orderBy('hostname')
                ->pluck('hostname', 'uuid')
                ->prepend('Tüm cihazlar', '')
                ->all(),

            /*
             * Son 24 saatin özeti: ekranın en üstünde "bugün ne kadar
             * düştü" sorusuna tek bakışta cevap.
             */
            'summary' => [
                'failed' => DeviceOperation::query()->visibleTo($request->user())
                    ->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
                'warning' => DeviceOperation::query()->visibleTo($request->user())
                    ->where('status', 'warning')->where('created_at', '>=', now()->subDay())->count(),
            ],
        ]);
    }
}
