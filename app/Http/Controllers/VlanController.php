<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Switching\VlanRequest;
use App\Models\NetworkSwitch;
use App\Models\Vlan;
use App\Services\ActivityRecorder;
use App\Services\Netconf\VlanConfigurator;
use App\Services\Switching\DependencyGuard;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
/**
 * Switchin VLAN'ları.
 *
 * Liste cihazdan NETCONF ile okunur; ekleme, değiştirme ve silme de cihaza
 * yazılır. Port üyeliği de buradan yönetilebilir — bir porta VLAN atamak
 * cihazda o portun üyeliğini değiştirmek demektir.
 *
 * VLAN adı yaratıldıktan sonra değiştirilemez: cihazda VLAN'ın kimliği adıdır.
 */
use Illuminate\View\View;

class VlanController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SwitchNavigationService $navigation,
        private ActivityRecorder $activity,
        private VlanConfigurator $vlans,
        private DependencyGuard $dependencies,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchVlans,switch', only: ['index']),
            /*
             * VLAN yazma cihaza commit atar; port üyeliğiyle aynı config
             * satırlarına dokunduğu için aynı yetkiye bağlıdır.
             */
            new Middleware('can:createSwitchVlans,switch', only: ['store']),
            new Middleware('can:updateSwitchVlans,switch', only: ['update']),
            new Middleware('can:deleteSwitchVlans,switch', only: ['destroy']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        return view('switches.vlans', [
            'switch' => $switch,
            'vlans' => $switch->vlans()
                ->withCount('ports')
                ->with(['ports' => fn ($query) => $query->orderBy('if_index')])
                ->orderBy('vlan_tag')
                ->get(),
            'ports' => $switch->ports()->orderBy('if_index')->get(),
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'vlans'),
        ]);
    }

    public function store(VlanRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->string('name')->toString();

        return $this->queueWrite(
            $switch,
            VlanConfigurator::class,
            'create',
            [
                $name,
                $request->vlanTag(),
                $request->l3Interface(),
                $request->ports(),
                $request->description(),
            ],
            "VLAN {$name} eklendi",
        );
    }

    public function update(VlanRequest $request, NetworkSwitch $switch, Vlan $vlan): RedirectResponse
    {
        /*
         * L3 arayüzü kaldırılıyorsa arayüz birimi de cihazdan gider ve
         * üstündeki adresler onunla birlikte silinir. Adres silmek ayrı bir
         * karardır; sessizce yapılmaz.
         */
        if ($request->l3Interface() !== $vlan->l3_interface && ($bound = $this->dependencies->forVlan($vlan)) !== []) {
            return back()->with('error', $this->dependencies->message(
                "VLAN {$vlan->name} L3 arayüzü değiştirilemez",
                $bound,
            ))->withInput();
        }

        return $this->queueWrite(
            $switch,
            VlanConfigurator::class,
            'update',
            [
                $vlan,
                $request->vlanTag(),
                $request->l3Interface(),
                $request->ports(),
                $request->description(),
            ],
            "VLAN {$vlan->name} güncellendi",
        );
    }

    public function destroy(NetworkSwitch $switch, Vlan $vlan): RedirectResponse
    {
        /*
         * L3 arayüzü VLAN'la birlikte gider; üstünde adres varsa o da giderdi.
         * Adres bloğu ataması da VLAN'ı kayıtla işaret ediyor: VLAN silinirse
         * blok sessizce "atanmamış"a dönerdi.
         */
        $bound = [
            ...$this->dependencies->forVlan($vlan),
            ...$this->dependencies->blocksForVlan($vlan),
        ];

        if ($bound !== []) {
            return back()->with('error', $this->dependencies->message("VLAN {$vlan->name}", $bound));
        }

        /*
         * İlişki ADLARI kuyruğa taşınır ve iş kaydı geri kurarken yeniden
         * yüklenir (SerializesModels); yazıcı üyelikleri okumadan silemez.
         */
        $vlan->load('ports.vlans');

        return $this->queueWrite(
            $switch,
            VlanConfigurator::class,
            'delete',
            [$vlan],
            "VLAN {$vlan->name} silindi",
        );
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.vlans';
    }
}
