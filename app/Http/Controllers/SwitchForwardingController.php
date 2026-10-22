<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RoutingInstanceType;
use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Forwarding\RibGroupRequest;
use App\Http\Requests\Forwarding\RoutingInstanceRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchRibGroup;
use App\Models\SwitchRoutingInstance;
use App\Services\ActivityRecorder;
use App\Services\Capabilities\DeviceCapabilities;
use App\Services\Netconf\RibGroupConfigurator;
use App\Services\Netconf\RoutingInstanceConfigurator;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Filtre tabanlı yönlendirme (FBF).
 *
 * ÜÇ PARÇASI VAR ve üçü de olmadan çalışmaz:
 *
 *   1. Yönlendirme tablosu   — trafiğin atılacağı tablo
 *   2. Firewall terimi       — "then routing-instance <tablo>"
 *   3. RIB grubu + bağlama   — arayüz yolları tabloya kopyalansın
 *
 * Üçüncüsü en çok atlanan parça: arayüz yolları (connected) yalnızca
 * inet.0'dadır. Kopyalanmazsa hedef tablo kendi arayüzlerine bile
 * ulaşamaz ve trafik sessizce düşer — cihaz hata VERMEZ.
 *
 * Ekran bu yüzden üçünü bir arada gösterir ve eksik parçayı söyler.
 * Firewall terimi kendi ekranında kalır; oradan bu tablolara atıf yapılır.
 */
class SwitchForwardingController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SwitchNavigationService $navigation,
        private ActivityRecorder $activity,
        private DeviceCapabilities $capabilities,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchForwarding,switch', only: ['index']),
            new Middleware('can:createSwitchForwarding,switch', only: ['storeInstance', 'storeGroup']),
            new Middleware('can:updateSwitchForwarding,switch', only: ['updateInstance', 'updateGroup', 'bind']),
            new Middleware('can:deleteSwitchForwarding,switch', only: ['destroyInstance', 'destroyGroup']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        $tables = $switch->routingInstances()->orderBy('name')->get();
        $groups = $switch->ribGroups()->orderBy('name')->get();

        return view('switches.forwarding', [
            'switch' => $switch,
            'instances' => $tables,
            'groups' => $groups,

            /*
             * Tür CİHAZA GÖRE daralır: EX2300 "forwarding"i reddediyor ve o
             * cihazda FBF virtual-router ile kurulmak zorunda (ölçüldü).
             * Ölçüm yoksa liste daraltılmaz.
             */
            'typeOptions' => $this->capabilities->filter(
                $switch,
                'routing.instance_types',
                RoutingInstanceType::options(),
            ),

            'boundGroup' => $switch->interface_routes_rib_group,
            'groupOptions' => ['' => 'Bağlı değil'] + $groups->pluck('name', 'name')->all(),

            // Eksik parçayı ekran söyler; Blade hesap yapmaz.
            'readiness' => $this->readiness($switch, $tables, $groups),

            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'forwarding'),
        ]);
    }

    public function storeInstance(RoutingInstanceRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->instanceName();

        return $this->queueWrite(
            $switch,
            RoutingInstanceConfigurator::class,
            'apply',
            [$name, $request->settings()],
            "yönlendirme tablosu {$name} eklendi",
        );
    }

    public function updateInstance(
        RoutingInstanceRequest $request,
        NetworkSwitch $switch,
        SwitchRoutingInstance $routingInstance,
    ): RedirectResponse {
        return $this->queueWrite(
            $switch,
            RoutingInstanceConfigurator::class,
            'apply',
            [$routingInstance->name, $request->settings()],
            "yönlendirme tablosu {$routingInstance->name} güncellendi",
        );
    }

    public function destroyInstance(
        NetworkSwitch $switch,
        SwitchRoutingInstance $routingInstance,
    ): RedirectResponse {
        return $this->queueWrite(
            $switch,
            RoutingInstanceConfigurator::class,
            'delete',
            [$routingInstance],
            "yönlendirme tablosu {$routingInstance->name} silindi",
        );
    }

    public function storeGroup(RibGroupRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->groupName();

        return $this->queueWrite(
            $switch,
            RibGroupConfigurator::class,
            'apply',
            [$name, $request->settings()],
            "rib grubu {$name} eklendi",
        );
    }

    public function updateGroup(
        RibGroupRequest $request,
        NetworkSwitch $switch,
        SwitchRibGroup $ribGroup,
    ): RedirectResponse {
        return $this->queueWrite(
            $switch,
            RibGroupConfigurator::class,
            'apply',
            [$ribGroup->name, $request->settings()],
            "rib grubu {$ribGroup->name} güncellendi",
        );
    }

    public function destroyGroup(NetworkSwitch $switch, SwitchRibGroup $ribGroup): RedirectResponse
    {
        return $this->queueWrite(
            $switch,
            RibGroupConfigurator::class,
            'delete',
            [$ribGroup],
            "rib grubu {$ribGroup->name} silindi",
        );
    }

    /**
     * Arayüz yollarının hangi gruba kopyalanacağı.
     *
     * FBF'nin sessiz kalan parçası: bu bağlama olmadan hedef tablo kendi
     * arayüzlerine bile ulaşamaz ve cihaz hata vermez.
     */
    public function bind(Request $request, NetworkSwitch $switch): RedirectResponse
    {
        $group = $request->string('rib_group')->trim()->toString() ?: null;

        return $this->queueWrite(
            $switch,
            RibGroupConfigurator::class,
            'applyInterfaceRoutes',
            [$group],
            $group === null
                ? 'arayüz yolu bağlaması kaldırıldı'
                : "arayüz yolları {$group} grubuna bağlandı",
        );
    }

    /**
     * FBF'nin hangi parçası eksik?
     *
     * @param  Collection<int, SwitchRoutingInstance>  $instances
     * @param  Collection<int, SwitchRibGroup>  $groups
     * @return list<string>
     */
    private function readiness(NetworkSwitch $switch, $instances, $groups): array
    {
        $missing = [];

        if ($instances->isEmpty()) {
            $missing[] = 'Yönlendirme tablosu yok: trafiğin atılacağı bir tablo gerekiyor.';
        }

        if ($groups->isEmpty()) {
            $missing[] = 'RIB grubu yok: arayüz yolları hedef tabloya kopyalanmadan trafik düşer.';
        } elseif ($switch->interface_routes_rib_group === null) {
            $missing[] = 'RIB grubu tanımlı ama arayüz yollarına BAĞLI DEĞİL; '
                .'bağlanmadan hiçbir etkisi yok.';
        }

        if ($switch->firewallFilters()->count() === 0) {
            $missing[] = 'Firewall filtresi yok: trafiği tabloya atacak terim '
                .'“Firewall” ekranında tanımlanır.';
        }

        return $missing;
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.forwarding';
    }
}
