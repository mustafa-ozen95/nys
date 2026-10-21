<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Bgp\BgpGroupRequest;
use App\Models\BgpGroup;
use App\Models\NetworkSwitch;
use App\Services\ActivityRecorder;
use App\Services\Netconf\BgpConfigurator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * BGP peer grupları. Her uç cihaza commit atar.
 */
class SwitchBgpGroupController extends Controller implements HasMiddleware
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

    public function store(BgpGroupRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $attributes = $request->deviceAttributes();

        return $this->queueWrite(
            $switch,
            BgpConfigurator::class,
            'createGroup',
            [$attributes],
            "BGP grubu {$attributes['name']} eklendi",
        );
    }

    /**
     * Ad DEĞİŞTİRİLEMEZ: cihazda grubun kimliği adıdır ve komşular ona
     * asılıdır. Yeniden adlandırma sil + yarat demek olurdu ve o sırada
     * oturumlar düşerdi.
     */
    public function update(BgpGroupRequest $request, NetworkSwitch $switch, BgpGroup $bgpGroup): RedirectResponse
    {
        return $this->queueWrite(
            $switch,
            BgpConfigurator::class,
            'updateGroup',
            [$bgpGroup, $request->deviceAttributes()],
            "BGP grubu {$bgpGroup->name} güncellendi",
        );
    }

    public function destroy(NetworkSwitch $switch, BgpGroup $bgpGroup): RedirectResponse
    {
        $name = $bgpGroup->name;

        return $this->queueWrite(
            $switch,
            BgpConfigurator::class,
            'deleteGroup',
            [$bgpGroup],
            "BGP grubu {$name} silindi",
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
