<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityChannel;
use App\Http\Requests\Switching\SwitchArchivalRequest;
use App\Models\NetworkSwitch;
use App\Services\ActivityRecorder;
use App\Services\Netconf\ArchivalWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Cihazın yapılandırma yedeği ayarını yazar.
 *
 * Ayar CİHAZA ÖZGÜDÜR: her switch kendi yedek sunucusunu gösterebilir.
 * Adres girilmemişse .env'deki varsayılan sunucu kullanılır.
 */
class SwitchArchivalController extends Controller implements HasMiddleware
{
    public function __construct(
        private ArchivalWriter $writer,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:configure,switch')];
    }

    public function __invoke(SwitchArchivalRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        /*
         * Önce cihaza yazılır, sonra kayda geçilir: commit atılamazsa
         * panelde cihazda olmayan bir ayar durmaz. Doldurulmuş ama
         * kaydedilmemiş model yazıcıya yeni değerleri taşır.
         */
        $switch->fill($request->settings());

        $result = $this->writer->apply($switch);

        $switch->save();

        if (! $result['committed']) {
            return back()->with('status', "{$switch->hostname} zaten istenen yedek ayarını taşıyor.");
        }

        /*
         * Adres kayda yazılmaz: kimlik içerir. Ne yapıldığı yazılır, nereye
         * yapıldığı cihaz ekranında durur.
         */
        $this->activity->record(
            $switch,
            'configured',
            sprintf(
                '%s cihazında yapılandırma yedeği %s.',
                $switch->hostname,
                $switch->archival_enabled ? 'açıldı' : 'kapatıldı',
            ),
            ActivityChannel::Config,
        );

        return back()->with('status', "{$switch->hostname} cihazına yazıldı: yapılandırma yedeği.");
    }
}
