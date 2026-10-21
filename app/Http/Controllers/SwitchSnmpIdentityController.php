<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityChannel;
use App\Http\Requests\Switching\SwitchSnmpIdentityRequest;
use App\Models\NetworkSwitch;
use App\Services\ActivityRecorder;
use App\Services\Netconf\SnmpIdentityWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Cihazın SNMP adını panelden düzeltir.
 *
 * Kuyruğa alınmaz: yazma işlemi iki kez çalışmamalı (kuyruk yeniden dener) ve
 * kullanıcının commit farkını anında görmesi gerekir. Cihaz tarafında geri
 * alınabilir commit kullanıldığı için işlem yarıda kalırsa cihaz kendini eski
 * hâline döndürür.
 */
class SwitchSnmpIdentityController extends Controller implements HasMiddleware
{
    public function __construct(
        private SnmpIdentityWriter $writer,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        // "update" envanteri düzenler; cihaza yazmak ayrı bir yetkidir.
        return [new Middleware('can:configure,switch')];
    }

    public function __invoke(SwitchSnmpIdentityRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->string('name')->toString();
        $result = $this->writer->rename($switch, $name, $request->location(), $request->contact());

        if (! $result['committed']) {
            return back()->with('status', "{$switch->hostname} zaten bu SNMP kimliğini taşıyor, değişiklik yapılmadı.");
        }

        $this->activity->record(
            $switch,
            'configured',
            "{$switch->hostname} cihazında SNMP adı \"{$name}\" olarak yazıldı.".$this->warningSuffix($result['warnings']),
            ActivityChannel::Config,
        );

        return back()->with('status', "{$switch->hostname} cihazına yazıldı ve commit onaylandı: SNMP adı \"{$name}\".");
    }

    /**
     * Cihazın uyarıları kayda geçer; işlemi durdurmazlar ama denetimde
     * görünmeleri gerekir.
     *
     * @param  list<string>  $warnings
     */
    private function warningSuffix(array $warnings): string
    {
        return $warnings === [] ? '' : ' Cihaz uyarısı: '.implode(' | ', $warnings);
    }
}
