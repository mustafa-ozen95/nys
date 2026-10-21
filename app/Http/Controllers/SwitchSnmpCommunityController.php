<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Switching\SnmpCommunityRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchSnmpCommunity;
use App\Services\ActivityRecorder;
use App\Services\Netconf\SnmpCommunityConfigurator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Cihazdaki SNMP community'leri.
 *
 * Community adı okuma parolası gibi çalışır: onu bilen cihazın bütün SNMP
 * verisini çeker. Bu yüzden ekranda basılmaz, kayda maskeli geçer ve
 * yazma cihaz yapılandırma yetkisi ister.
 */
class SwitchSnmpCommunityController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SnmpCommunityConfigurator $communities,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:configure,switch')];
    }

    public function store(SnmpCommunityRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        return $this->queueWrite(
            $switch,
            SnmpCommunityConfigurator::class,
            'create',
            [$request->string('name')->toString(), $request->string('authorization')->toString(), $request->clientList()],
            'SNMP community eklendi',
        );
    }

    public function update(
        SnmpCommunityRequest $request,
        NetworkSwitch $switch,
        SwitchSnmpCommunity $snmpCommunity,
    ): RedirectResponse {
        return $this->queueWrite(
            $switch,
            SnmpCommunityConfigurator::class,
            'update',
            [$snmpCommunity, $request->string('authorization')->toString(), $request->clientList()],
            'SNMP community güncellendi',
        );
    }

    public function destroy(NetworkSwitch $switch, SwitchSnmpCommunity $snmpCommunity): RedirectResponse
    {
        return $this->queueWrite(
            $switch,
            SnmpCommunityConfigurator::class,
            'delete',
            [$snmpCommunity],
            'SNMP community silindi',
        );
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.settings';
    }
}
