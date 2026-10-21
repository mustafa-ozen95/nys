<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Bgp\BgpNeighborRequest;
use App\Models\BgpGroup;
use App\Models\BgpNeighbor;
use App\Models\NetworkSwitch;
use App\Services\ActivityRecorder;
use App\Services\Netconf\BgpConfigurator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * BGP komşuları. Komşu daima bir gruba bağlıdır; grupsuz komşu Junos'ta
 * tanımlanamaz.
 *
 * Rotalar iç içedir ve scopeBindings ile bağlanır: grup seçili switche,
 * komşu seçili gruba ait olmak zorundadır. Bunsuz, A switchinde yetkisi olan
 * biri B switchinin komşusunu değiştirebilirdi.
 */
class SwitchBgpNeighborController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private ActivityRecorder $activity,
        private BgpConfigurator $bgp,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:createSwitchBgp,switch', only: ['store']),
            new Middleware('can:updateSwitchBgp,switch', only: ['update']),
            new Middleware('can:deleteSwitchBgp,switch', only: ['destroy']),
        ];
    }

    public function store(BgpNeighborRequest $request, NetworkSwitch $switch, BgpGroup $bgpGroup): RedirectResponse
    {
        $attributes = $request->deviceAttributes();

        return $this->queueWrite(
            $switch,
            BgpConfigurator::class,
            'createNeighbor',
            [$bgpGroup, $attributes],
            "komşu {$attributes['address']} eklendi",
        );
    }

    public function update(
        BgpNeighborRequest $request,
        NetworkSwitch $switch,
        BgpGroup $bgpGroup,
        BgpNeighbor $neighbor,
    ): RedirectResponse {
        return $this->queueWrite(
            $switch,
            BgpConfigurator::class,
            'updateNeighbor',
            [$neighbor, $request->deviceAttributes()],
            "komşu {$neighbor->address} güncellendi",
        );
    }

    public function destroy(NetworkSwitch $switch, BgpGroup $bgpGroup, BgpNeighbor $neighbor): RedirectResponse
    {
        $address = $neighbor->address;

        return $this->queueWrite(
            $switch,
            BgpConfigurator::class,
            'deleteNeighbor',
            [$neighbor],
            "komşu {$address} silindi",
        );
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.bgp';
    }
}
