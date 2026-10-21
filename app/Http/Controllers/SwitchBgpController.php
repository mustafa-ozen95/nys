<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Bgp\AutonomousSystemRequest;
use App\Models\NetworkSwitch;
use App\Services\ActivityRecorder;
use App\Services\Bgp\BgpViewService;
use App\Services\Netconf\BgpConfigurator;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Switchin BGP ekranı: gruplar, komşular ve cihazın AS numarası.
 *
 * Ekran adım adımdır çünkü cihaz da öyle: AS numarası tanımlı değilken hiçbir
 * grup commit edilemez ("must define local autonomous system when enabling
 * BGP"). Bu yüzden AS numarası yoksa grup formu hiç açılmaz.
 */
class SwitchBgpController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SwitchNavigationService $navigation,
        private BgpViewService $view,
        private ActivityRecorder $activity,
        private BgpConfigurator $bgp,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchBgp,switch', only: ['index']),
            // AS numarasını değiştirmek cihaza commit atar.
            new Middleware('can:updateSwitchBgp,switch', only: ['autonomousSystem']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        return view('switches.bgp', [
            'switch' => $switch,
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'bgp'),
            ...$this->view->screen($switch),
        ]);
    }

    public function autonomousSystem(AutonomousSystemRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $asn = $request->string('autonomous_system')->toString();

        return $this->queueWrite(
            $switch,
            BgpConfigurator::class,
            'setAutonomousSystem',
            [$asn],
            "AS numarası {$asn} olarak ayarlandı",
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
