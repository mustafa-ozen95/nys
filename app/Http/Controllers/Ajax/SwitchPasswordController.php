<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Enums\ActivityChannel;
use App\Http\Controllers\Controller;
use App\Models\NetworkSwitch;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Cihaz erişim parolasını ekranda gösterir.
 *
 * Panelin genel kuralı parolayı hiçbir ekranda basmamaktır. Burası bilinçli
 * bir İSTİSNADIR: sahada konsoldan ya da panel dışı bir araçla bağlanmak
 * gerektiğinde parola bir yerden okunmak zorunda ve o yer, kimin ne zaman
 * okuduğunu kaydeden bir yer olmalı.
 *
 * Bu yüzden:
 *  - Ayrı bir yetki ister ("secret"), switch başına verilir ve varsayılan
 *    olarak yalnızca yöneticidedir.
 *  - HER GÖSTERİM kayda geçer; kayıt silinemez (hash zinciri).
 *  - Yanıt önbelleğe alınmaz ve listede toplu basılmaz; tek tek istenir.
 *  - Adres GET değil POST'tur: tarayıcı geçmişine, sunucu erişim kaydına ve
 *    ön yüklemeye takılmasın.
 */
class SwitchPasswordController extends Controller implements HasMiddleware
{
    public function __construct(private ActivityRecorder $activity) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:secret,switch')];
    }

    public function __invoke(Request $request, NetworkSwitch $switch): JsonResponse
    {
        $credential = $switch->netconfCredential ?? $switch->sshCredential;

        abort_if($credential?->password === null, 404);

        $this->activity->record(
            $switch,
            'viewed',
            "{$switch->hostname} · cihaz parolası görüntülendi ({$credential->username})",
            ActivityChannel::Config,
        );

        return response()
            ->json([
                'username' => $credential->username,
                'password' => $credential->password,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }
}
