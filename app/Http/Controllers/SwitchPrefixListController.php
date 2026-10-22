<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Switching\PrefixListEntryRequest;
use App\Http\Requests\Switching\PrefixListSettingsRequest;
use App\Http\Requests\Switching\SwitchPrefixListRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchPrefixList;
use App\Models\SwitchPrefixListEntry;
use App\Services\ActivityRecorder;
use App\Services\Addressing\AddressCandidateService;
use App\Services\Netconf\PrefixListConfigurator;
use App\Services\Switching\DependencyGuard;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
/**
 * Switchin prefix listeleri ve içindeki prefixler.
 *
 * Listeler cihazdan eşitlenir ve panelden yazılır. Firewall filtreleri bu
 * listelere dayandığı için boş bir liste gerçek bir bulgudur: uplink
 * çıkışındaki filtre boş bir listeye dayanıyorsa geçen bütün trafiği düşürür.
 */
use Illuminate\View\View;

class SwitchPrefixListController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SwitchNavigationService $navigation,
        private ActivityRecorder $activity,
        private PrefixListConfigurator $lists,
        private AddressCandidateService $candidates,
        private DependencyGuard $dependencies,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchPrefixLists,switch', only: ['index']),
            // Prefix list'ler cihaza yazılır; firewall filtreleri onlara dayanır.
            new Middleware('can:createSwitchPrefixLists,switch', only: ['store', 'storeEntry']),
            // Listenin kendi ayarları (apply-path) düzenleme yetkisi ister.
            new Middleware('can:updateSwitchPrefixLists,switch', only: ['update']),
            new Middleware('can:deleteSwitchPrefixLists,switch', only: ['destroy', 'destroyEntry']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        return view('switches.prefix-lists', [
            'switch' => $switch,
            'lists' => $switch->prefixLists()->with('entries')->orderBy('name')->get(),
            // Ağ elle yazılmaz, Adresleme'de tanımlı bloklardan seçilir.
            'networkOptions' => $this->candidates->blockOptions(),
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'prefix-lists'),
        ]);
    }

    public function store(SwitchPrefixListRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->string('name')->toString();

        return $this->queueWrite(
            $switch,
            PrefixListConfigurator::class,
            'createList',
            [$name],
            "{$name} eklendi",
        );
    }

    /**
     * Listenin kendi ayarları: apply-path ifadeleri.
     *
     * Junos listeyi bu ifadelerle DİNAMİK doldurur; girdileri yapılandırmada
     * yazılı olmadığı için panel onları göremez ama ifadenin kendisi
     * yönetilebilir; böyle bir liste salt okunur sayılmaz.
     */
    public function update(
        PrefixListSettingsRequest $request,
        NetworkSwitch $switch,
        SwitchPrefixList $prefixList,
    ): RedirectResponse {
        return $this->queueWrite(
            $switch,
            PrefixListConfigurator::class,
            'updateSettings',
            [$prefixList, $request->applyPaths()],
            "{$prefixList->name} güncellendi",
        );
    }

    public function destroy(NetworkSwitch $switch, SwitchPrefixList $prefixList): RedirectResponse
    {
        $bound = $this->dependencies->forPrefixList($prefixList);

        // Hâlâ atıf yapan bir politika, firewall terimi ya da blok varsa
        // cihaz commit'i reddeder; istek oraya kadar gitmemeli.
        if ($bound !== []) {
            return back()->with('error', $this->dependencies->message("Prefix list {$prefixList->name}", $bound));
        }

        $name = $prefixList->name;

        return $this->queueWrite(
            $switch,
            PrefixListConfigurator::class,
            'deleteList',
            [$prefixList],
            "{$name} silindi",
        );
    }

    public function storeEntry(
        PrefixListEntryRequest $request,
        NetworkSwitch $switch,
        SwitchPrefixList $prefixList,
    ): RedirectResponse {
        $cidr = $request->cidr();

        return $this->queueWrite(
            $switch,
            PrefixListConfigurator::class,
            'addEntry',
            [$prefixList, $cidr],
            "{$cidr} eklendi",
        );
    }

    public function destroyEntry(
        NetworkSwitch $switch,
        SwitchPrefixList $prefixList,
        SwitchPrefixListEntry $entry,
    ): RedirectResponse {
        $cidr = $entry->cidr;

        return $this->queueWrite(
            $switch,
            PrefixListConfigurator::class,
            'removeEntry',
            [$prefixList, $entry],
            "{$cidr} silindi",
        );
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.prefix-lists';
    }
}
