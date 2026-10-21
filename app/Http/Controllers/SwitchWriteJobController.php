<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NetworkSwitch;
use App\Models\SwitchWriteJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Kuyruğa alınmış bir cihaz yazmasının durumu.
 *
 * Yazma HTTP isteğini bekletmez; ekran sonucu buradan yoklar.
 * Websocket kurulmadı: bu ölçekte ek altyapı, ek kırılma noktası demek.
 *
 * Yetkilendirme: switchi GÖRME yetkisi yeter — burada yalnızca kullanıcının
 * kendi başlattığı yazmanın sonucu okunuyor, cihaza dokunulmuyor.
 */
class SwitchWriteJobController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:view,switch')];
    }

    public function __invoke(NetworkSwitch $switch, SwitchWriteJob $writeJob): JsonResponse
    {
        return response()->json([
            'uuid' => $writeJob->uuid,
            'status' => $writeJob->status->value,
            'label' => $writeJob->status->label(),
            'badge' => $writeJob->status->badge()->value,
            'summary' => $writeJob->summary,
            'message' => $writeJob->message,
            'diff' => $writeJob->diff,
            'finished' => $writeJob->status->isFinished(),
        ]);
    }
}
