<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Switching\RstpBridgeRequest;
use App\Http\Requests\Switching\RstpInterfaceRequest;
use App\Http\Requests\Switching\StormControlRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchRstpInterface;
use App\Models\SwitchStormControl;
use App\Services\ActivityRecorder;
use App\Services\Netconf\RstpConfigurator;
use App\Services\Netconf\StormControlConfigurator;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Spanning tree (RSTP) ve fırtına denetimi.
 *
 * İkisi de PORT KORUMASIDIR ve aynı ekranda durur: biri döngüye, diğeri
 * yayın fırtınasına karşı. Fırtına denetimi bugüne kadar hiç yönetilmiyordu —
 * cihazda vardı, panelde yoktu.
 *
 * Panelin en geniş etkili L2 yazma yoludur: köprü önceliği kök seçimini
 * değiştirir, "edge" tek bir portta döngü yaratabilir. Bu yüzden kendi
 * yetkisi vardır.
 *
 * Ayarlar cihazdan okunur; panelde girilen değer değil cihazın gerçeği
 * gösterilir.
 */
class SwitchRstpController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SwitchNavigationService $navigation,
        private RstpConfigurator $rstp,
        private StormControlConfigurator $storm,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchRstp,switch', only: ['index']),
            new Middleware('can:updateSwitchRstp,switch', only: ['bridge', 'update', 'stormShutdown', 'stormUpdate']),
            new Middleware('can:createSwitchRstp,switch', only: ['store', 'stormStore']),
            new Middleware('can:deleteSwitchRstp,switch', only: ['destroy', 'stormDestroy']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        $allKeyword = ($switch->schema_profile ?? JunosSchemaProfile::PreEls)
            ->dialect()
            ->supportsAllKeyword();

        /*
         * Yalnızca ANAHTARLAMA modundaki portlar sunulur. Cihaz aksini
         * reddediyor: "XSTP : Interface ge-0/0/20.0 is not enabled for
         * Ethernet Switching" — routed ve yapılandırılmamış portlarda
         * spanning tree tanımlanamaz (jn32-test ile doğrulandı).
         */
        $switchingPorts = $switch->ports()
            ->whereIn('port_mode', [PortMode::Access->value, PortMode::Trunk->value])
            ->orderBy('if_index')
            ->pluck('name', 'name')
            ->all();

        return view('switches.rstp', [
            'switch' => $switch,
            'allKeyword' => $allKeyword,
            'interfaces' => $switch->rstpInterfaces()->orderBy('interface_name')->get(),
            'stormControls' => $switch->stormControls()->orderBy('interface_name')->get(),
            'priorityOptions' => $this->priorityOptions(),

            /*
             * SPANNING TREE listesi. "protocols rstp interface all" İKİ
             * lehçede de kabul ediliyor (test2 ve test4'te ölçüldü), bu
             * yüzden kısayol her zaman durur.
             */
            'interfaceOptions' => ['all' => 'Bütün portlar (all)'] + $switchingPorts,

            /*
             * FIRTINA listesi AYRIDIR. Aynı sözcük komşu hiyerarşide başka
             * sonuç veriyor: ELS "interfaces interface all" için "invalid
             * interface type in 'all'" diyor (test4'te ölçüldü). İki ekran
             * tek listeyi paylaşırsa biri için doğru olan öbürüne yanlış
             * yazılır (bkz. .ai/rules/junos.md).
             */
            'stormInterfaceOptions' => ($allKeyword ? ['all' => 'Bütün portlar (all)'] : [])
                + $switchingPorts,

            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'rstp'),
        ]);
    }

    public function bridge(RstpBridgeRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        return $this->queueWrite(
            $switch,
            RstpConfigurator::class,
            'applyBridge',
            [$request->boolean('enabled'), $request->bridgePriority(), $request->boolean('bpdu_block_on_edge')],
            'spanning tree köprü ayarları',
        );
    }

    public function store(RstpInterfaceRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->string('interface_name')->toString();

        return $this->queueWrite(
            $switch,
            RstpConfigurator::class,
            'applyInterface',
            $this->interfaceArguments($request, $name),
            "{$name} eklendi",
        );
    }

    public function update(
        RstpInterfaceRequest $request,
        NetworkSwitch $switch,
        SwitchRstpInterface $rstpInterface,
    ): RedirectResponse {
        return $this->queueWrite(
            $switch,
            RstpConfigurator::class,
            'applyInterface',
            $this->interfaceArguments($request, $rstpInterface->interface_name),
            "{$rstpInterface->interface_name} güncellendi",
        );
    }

    public function destroy(NetworkSwitch $switch, SwitchRstpInterface $rstpInterface): RedirectResponse
    {
        $name = $rstpInterface->interface_name;

        return $this->queueWrite(
            $switch,
            RstpConfigurator::class,
            'deleteInterface',
            [$rstpInterface],
            "{$name} silindi",
        );
    }

    /**
     * Eşik aşılınca portun kapatılması: cihaz başına tek ayar.
     */
    public function stormShutdown(Request $request, NetworkSwitch $switch): RedirectResponse
    {
        return $this->queueWrite(
            $switch,
            StormControlConfigurator::class,
            'applyShutdown',
            [$request->boolean('storm_control_shutdown')],
            'fırtına denetimi kapatma ayarı',
        );
    }

    public function stormStore(StormControlRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->interfaceName();

        return $this->queueWrite(
            $switch,
            StormControlConfigurator::class,
            'apply',
            [$name, $request->settings()],
            "{$name} fırtına denetimi eklendi",
        );
    }

    public function stormUpdate(
        StormControlRequest $request,
        NetworkSwitch $switch,
        SwitchStormControl $stormControl,
    ): RedirectResponse {
        $name = $stormControl->interface_name;

        return $this->queueWrite(
            $switch,
            StormControlConfigurator::class,
            'apply',
            [$name, $request->settings()],
            "{$name} fırtına denetimi güncellendi",
        );
    }

    public function stormDestroy(NetworkSwitch $switch, SwitchStormControl $stormControl): RedirectResponse
    {
        $name = $stormControl->interface_name;

        return $this->queueWrite(
            $switch,
            StormControlConfigurator::class,
            'delete',
            [$stormControl],
            "{$name} fırtına denetimi kaldırıldı",
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    /**
     * Arayüz ayarlarının CİHAZA GİDECEK argümanları.
     *
     * Yazma kuyruğa alındığı için burada çağrı YAPILMAZ, yalnızca argümanlar
     * hazırlanır: isteğin ömrü kuyruktaki işten kısa.
     *
     * @return array<int, mixed>
     */
    private function interfaceArguments(RstpInterfaceRequest $request, string $name): array
    {
        return [
            $name,
            $request->boolean('edge'),
            $request->boolean('no_root_port'),
            filled($request->input('cost')) ? $request->integer('cost') : null,
            filled($request->input('priority')) ? $request->integer('priority') : null,
        ];
    }

    /**
     * Köprü önceliği serbest sayı değildir; Junos 4096'nın katlarını kabul
     * eder ve "4k" biçiminde yazar.
     *
     * @return array<string, string>
     */
    private function priorityOptions(): array
    {
        $options = ['' => 'Cihazın varsayılanı (32k)'];

        foreach (RstpBridgeRequest::PRIORITIES as $priority) {
            $options[$priority] = $priority === '0' ? '0 (en yüksek öncelik)' : $priority;
        }

        return $options;
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.rstp';
    }
}
