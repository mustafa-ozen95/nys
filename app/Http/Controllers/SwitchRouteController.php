<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityChannel;
use App\Enums\AddressPurpose;
use App\Http\Requests\Switching\SwitchRouteRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchRoute;
use App\Services\ActivityRecorder;
use App\Services\Addressing\AddressCandidateService;
use App\Services\Netconf\RouteConfigurator;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switchin route tablosu.
 *
 * Tablo cihazdan NETCONF ile okunur; ekleme, değiştirme ve silme de cihaza
 * yazılır. Envanter kaydı elle düzenlenmez — her işlemden sonra tablo
 * cihazdan geri okunur, panel niyeti değil cihazın gerçeğini gösterir.
 *
 * Yalnızca statik yollar yazılabilir; öğrenilen yollar (Direct, Local, OSPF,
 * BGP) yalnızca görüntülenir.
 */
class SwitchRouteController extends Controller implements HasMiddleware
{
    public function __construct(
        private SwitchNavigationService $navigation,
        private ActivityRecorder $activity,
        private RouteConfigurator $routes,
        private AddressCandidateService $candidates,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchRoutes,switch', only: ['index']),
            // Cihaza yazan işlemler "update" değil "configure" ister.
            new Middleware('can:createSwitchRoutes,switch', only: ['store']),
            new Middleware('can:updateSwitchRoutes,switch', only: ['update']),
            new Middleware('can:deleteSwitchRoutes,switch', only: ['destroy']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        $routes = $switch->routes()->orderBy('destination')->get();

        return view('switches.routes', [
            'switch' => $switch,
            'routes' => $routes,
            // Blade karar vermez: hangi satırın düzenlenebilir olduğu burada belli olur.
            'writable' => $routes->mapWithKeys(
                fn (SwitchRoute $route): array => [$route->uuid => $this->routes->isWritable($route)],
            )->all(),
            /*
             * Hedef ağ Adresleme'deki bloklardan seçilir; elle giriş açıktır
             * çünkü varsayılan route (0.0.0.0/0) ve sağlayıcı özet ağları
             * envanterde durmaz.
             */
            /*
             * Başka bir cihaza bağlanmış blok LİSTEDE YOKTUR: o aralık orada
             * kullanılıyor ve ikinci kez route edilmesi çakışma demektir.
             * Bu switchin kendi blokları listede kalır.
             */
            'networkOptions' => $this->candidates->blockOptions(switch: $switch),

            // Sonraki atlama elle yazılmaz; uplink ve peering havuzlarından seçilir.
            'nextHopOptions' => $this->candidates->hostOptions([
                AddressPurpose::Uplink,
                AddressPurpose::Peering,
            ]),
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'routes'),
        ]);
    }

    public function store(SwitchRouteRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $cidr = $request->cidr();

        $result = $this->routes->create(
            $switch,
            $request->destination(),
            $request->prefixLength(),
            $request->settings(),
        );

        return $this->respond($switch, $result, "{$cidr} eklendi", 'route eklendi');
    }

    public function update(
        SwitchRouteRequest $request,
        NetworkSwitch $switch,
        SwitchRoute $route,
    ): RedirectResponse {
        $this->guardWritable($route);

        $cidr = $request->cidr();

        $result = $this->routes->replace(
            $switch,
            $route,
            $request->destination(),
            $request->prefixLength(),
            $request->settings(),
        );

        return $this->respond($switch, $result, "{$cidr} güncellendi", 'route güncellendi');
    }

    public function destroy(NetworkSwitch $switch, SwitchRoute $route): RedirectResponse
    {
        $this->guardWritable($route);

        $cidr = $route->cidr;
        $result = $this->routes->delete($switch, $route);

        return $this->respond($switch, $result, "{$cidr} silindi", 'route silindi');
    }

    /**
     * Öğrenilen yollar yapılandırmada yoktur; silinmeye ya da değiştirilmeye
     * çalışılırsa cihaz reddeder. İstek buraya kadar gelmemeli.
     */
    private function guardWritable(SwitchRoute $route): void
    {
        abort_unless(
            $this->routes->isWritable($route),
            Response::HTTP_FORBIDDEN,
            'Yalnızca statik route’lar değiştirilebilir; bu yol cihaz tarafından öğrenilmiştir.',
        );
    }

    /**
     * @param  array{diff: string, committed: bool, warnings: list<string>}  $result
     */
    private function respond(
        NetworkSwitch $switch,
        array $result,
        string $message,
        string $action,
    ): RedirectResponse {
        if (! $result['committed']) {
            return $this->back($switch, 'Cihaz zaten bu durumda, değişiklik yapılmadı.');
        }

        $this->activity->record(
            $switch,
            'configured',
            "{$switch->hostname} cihazında {$action}: ".str_replace("\n", ' ', $result['diff']),
            ActivityChannel::Config,
        );

        return $this->back($switch, "{$message} ve cihaza yazıldı.");
    }

    private function back(NetworkSwitch $switch, string $status): RedirectResponse
    {
        return redirect()->route('switches.routes', $switch)->with('status', $status);
    }
}
