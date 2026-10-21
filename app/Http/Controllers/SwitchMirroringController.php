<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Switching\AnalyzerRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchAnalyzer;
use App\Services\ActivityRecorder;
use App\Services\Netconf\AnalyzerConfigurator;
use App\Services\Switching\PortMirroringViewService;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Port aynalama (analyzer): bir portun ya da VLAN'ın trafiğini bir hedefe
 * kopyalar.
 *
 * KENDİ YETKİ KAYNAĞIDIR (switch_mirroring) ve spanning tree ile fırtına
 * denetiminden bilinçli olarak AYRIDIR. Onlar port KORUMASIDIR; aynalama bir
 * teşhis aracıdır ve aynı zamanda bir dinleme yoludur: çıkış portuna erişebilen
 * kişi, kaynak portun bütün trafiğini görür. Döngü korumasını yönetme yetkisi,
 * trafiği kopyalama yetkisi anlamına gelmemeli — "hiçbir şey onu ima etmez"
 * (bkz. .ai/rules/yetki.md).
 *
 * Cihazın kendi ayarları (switches.configure) da doğru yer değildir: o yetki
 * yalnızca SNMP adı ve yapılandırma yedeğidir.
 */
class SwitchMirroringController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SwitchNavigationService $navigation,
        private PortMirroringViewService $view,
        private AnalyzerConfigurator $analyzers,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchMirroring,switch', only: ['index']),
            new Middleware('can:createSwitchMirroring,switch', only: ['store']),
            new Middleware('can:updateSwitchMirroring,switch', only: ['update']),
            new Middleware('can:deleteSwitchMirroring,switch', only: ['destroy']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        return view('switches.mirroring', [
            'switch' => $switch,
            'lossPriorityOptions' => $this->lossPriorityOptions(),
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'mirroring'),
            ...$this->view->screen($switch),
        ]);
    }

    public function store(AnalyzerRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->analyzerName();

        return $this->queueWrite(
            $switch,
            AnalyzerConfigurator::class,
            'apply',
            [$name, $request->settings()],
            "{$name} aynalaması eklendi",
        );
    }

    public function update(
        AnalyzerRequest $request,
        NetworkSwitch $switch,
        SwitchAnalyzer $analyzer,
    ): RedirectResponse {
        $name = (string) $analyzer->name;

        return $this->queueWrite(
            $switch,
            AnalyzerConfigurator::class,
            'apply',
            [$name, $request->settings()],
            "{$name} aynalaması güncellendi",
        );
    }

    public function destroy(NetworkSwitch $switch, SwitchAnalyzer $analyzer): RedirectResponse
    {
        $name = (string) $analyzer->name;

        return $this->queueWrite(
            $switch,
            AnalyzerConfigurator::class,
            'delete',
            [$analyzer],
            "{$name} aynalaması kaldırıldı",
        );
    }

    /**
     * @return array<string, string>
     */
    private function lossPriorityOptions(): array
    {
        $options = ['' => 'Cihazın varsayılanı'];

        foreach (AnalyzerRequest::LOSS_PRIORITIES as $priority) {
            $options[$priority] = $priority;
        }

        return $options;
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.mirroring';
    }
}
