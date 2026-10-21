<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PortMode;
use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Aggregation\DeviceCountRequest;
use App\Http\Requests\Aggregation\LinkAggregationRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\Vlan;
use App\Services\ActivityRecorder;
use App\Services\Netconf\LinkAggregationConfigurator;
use App\Services\Switching\LinkAggregationViewService;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Bağlantı toplama (LAG): ae limiti ve ae arayüzleri.
 *
 * ae arayüzleri panelde oluşturulmaz, CHASSIS yaratır: limit kaç ise o kadarı
 * vardır. Ekranın yaptığı, var olan bir ae'yi yapılandırmak ve üye atamaktır.
 */
class SwitchLinkAggregationController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SwitchNavigationService $navigation,
        private LinkAggregationViewService $view,
        private ActivityRecorder $activity,
        private LinkAggregationConfigurator $aggregation,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchAggregation,switch', only: ['index']),
            new Middleware('can:createSwitchAggregation,switch', only: ['store']),
            new Middleware('can:updateSwitchAggregation,switch', only: ['update', 'deviceCount']),
            new Middleware('can:deleteSwitchAggregation,switch', only: ['destroy']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        return view('switches.aggregation', [
            'switch' => $switch,
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'aggregation'),
            ...$this->view->screen($switch),
        ]);
    }

    public function deviceCount(DeviceCountRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $count = $request->integer('device_count');

        return $this->queueWrite(
            $switch,
            LinkAggregationConfigurator::class,
            'setDeviceCount',
            [$count],
            "ae limiti {$count} yapıldı",
        );
    }

    public function update(
        LinkAggregationRequest $request,
        NetworkSwitch $switch,
        SwitchPort $aggregate,
    ): RedirectResponse {
        $members = $this->portNames($switch, (array) $request->input('members', []));

        return $this->queueWrite(
            $switch,
            LinkAggregationConfigurator::class,
            'apply',
            [$aggregate, $request->aggregationOptions(), $members, $this->interfaceSettings($switch, $request)],
            "{$aggregate->name} yapılandırıldı",
        );
    }

    public function destroy(NetworkSwitch $switch, SwitchPort $aggregate): RedirectResponse
    {
        $name = $aggregate->name;

        return $this->queueWrite(
            $switch,
            LinkAggregationConfigurator::class,
            'delete',
            [$aggregate],
            "{$name} yapılandırması silindi",
        );
    }

    /**
     * Arayüzün toplama dışındaki ayarları; ae'ye özgü olanlarla aynı commit'te
     * yazılır.
     *
     * @return array<string, mixed>
     */
    private function interfaceSettings(NetworkSwitch $switch, LinkAggregationRequest $request): array
    {
        $mode = PortMode::from($request->string('port_mode')->toString());
        $vlans = $this->vlanNames($switch, (array) $request->input('vlans', []));
        $untagged = $this->vlanNames($switch, array_filter([$request->string('untagged_vlan')->toString()]));

        return [
            'description' => $request->string('description')->toString() ?: null,
            'enabled' => $request->boolean('enabled'),
            'mode' => $mode,
            'vlans' => $vlans,
            'untagged' => $untagged[0] ?? null,
            // Etiketsiz VLAN'ın etiketi trunk'ta arayüz seviyesinde durur.
            'native_vlan_tag' => $mode === PortMode::Trunk
                ? $this->untaggedTag($switch, $request->string('untagged_vlan')->toString())
                : null,
        ];
    }

    /**
     * @param  array<int, string>  $uuids
     * @return list<string>
     */
    private function portNames(NetworkSwitch $switch, array $uuids): array
    {
        return $switch->ports()->whereIn('uuid', $uuids)->pluck('name')->all();
    }

    /**
     * @param  array<int, string>  $uuids
     * @return list<string>
     */
    private function vlanNames(NetworkSwitch $switch, array $uuids): array
    {
        return $switch->vlans()->whereIn('uuid', $uuids)->pluck('name')->all();
    }

    private function untaggedTag(NetworkSwitch $switch, string $uuid): ?int
    {
        if ($uuid === '') {
            return null;
        }

        return $switch->vlans()->where('uuid', $uuid)->value('vlan_tag');
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.aggregation';
    }
}
