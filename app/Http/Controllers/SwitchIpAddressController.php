<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Switching\SwitchIpAddressRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchIpAddress;
use App\Models\Vlan;
use App\Services\ActivityRecorder;
use App\Services\Netconf\IpAddressConfigurator;
use App\Services\Netconf\ManageableInterface;
use App\Services\Switching\SwitchAddressCandidateService;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
/**
 * Switchin arayüzlerine tanımlı IP adresleri.
 *
 * Adresler cihazdan eşitlenir ve panelden yazılır. Yönetim arayüzünün adresi
 * değiştirilemez: panelin cihaza erişimi kesilirdi.
 */
use Symfony\Component\HttpFoundation\Response;

class SwitchIpAddressController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SwitchNavigationService $navigation,
        private SwitchAddressCandidateService $candidates,
        private ActivityRecorder $activity,
        private IpAddressConfigurator $addresses,
        private ManageableInterface $manageable,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchIpAddresses,switch', only: ['index']),
            // Adresler cihaza yazılır; porta adres vermek portu routed yapar.
            new Middleware('can:createSwitchIpAddresses,switch', only: ['store']),
            new Middleware('can:updateSwitchIpAddresses,switch', only: ['update']),
            new Middleware('can:deleteSwitchIpAddresses,switch', only: ['destroy']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        return view('switches.ip-addresses', [
            'switch' => $switch,
            'addresses' => $addresses = $switch->ipAddresses()->with(['port', 'vlan'])->orderBy('interface_name')->get(),
            /*
             * Hangi adresin düzenlenebileceği burada belli olur; Blade karar
             * vermez. me0 gibi yönetim arayüzleri panelden değiştirilemez.
             */
            'editable' => $addresses->mapWithKeys(fn (SwitchIpAddress $address): array => [
                $address->uuid => $this->manageable->isManageable($address),
            ])->all(),
            'lockReason' => $addresses->mapWithKeys(fn (SwitchIpAddress $address): array => [
                $address->uuid => $this->manageable->reason($address),
            ])->all(),
            'portOptions' => $switch->ports()
                ->orderBy('if_index')
                ->pluck('name', 'uuid')
                ->all(),
            'vlanOptions' => $switch->vlans()
                ->orderBy('vlan_tag')
                ->get()
                ->mapWithKeys(fn (Vlan $vlan): array => [$vlan->uuid => $vlan->name])
                ->all(),
            'addressOptions' => $options = $this->candidates->groupedOptions($switch),

            // Atanmış blok yoksa yeni adres eklenemez; pencere yalnızca
            // ne yapılması gerektiğini söyler, boş bir form sunmaz.
            'canAdd' => $options !== [],

            /*
             * Düzenleme penceresinin listesi ayrıdır: kaydın kendi adresi de
             * içinde olmalı, yoksa bloğu kalmamış bir adres düzenlenemez.
             * Bloklar bir kez okunur, geri kalanı dizi işlemidir.
             */
            'editOptions' => $addresses->mapWithKeys(fn (SwitchIpAddress $address): array => [
                $address->uuid => $this->candidates->withCurrent($options, $address),
            ])->all(),
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'ip-addresses'),
        ]);
    }

    public function store(SwitchIpAddressRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $cidr = $request->cidr();

        return $this->queueWrite(
            $switch,
            IpAddressConfigurator::class,
            'add',
            [$request->resolvedInterfaceName(), $cidr],
            "{$cidr} eklendi",
        );
    }

    public function update(
        SwitchIpAddressRequest $request,
        NetworkSwitch $switch,
        SwitchIpAddress $ipAddress,
    ): RedirectResponse {
        $this->guardManageable($ipAddress);

        $cidr = $request->cidr();

        return $this->queueWrite(
            $switch,
            IpAddressConfigurator::class,
            'replace',
            [$ipAddress, $request->resolvedInterfaceName(), $cidr],
            "{$cidr} güncellendi",
        );
    }

    public function destroy(NetworkSwitch $switch, SwitchIpAddress $ipAddress): RedirectResponse
    {
        $this->guardManageable($ipAddress);

        $cidr = $ipAddress->cidr;

        return $this->queueWrite(
            $switch,
            IpAddressConfigurator::class,
            'remove',
            [$ipAddress],
            "{$cidr} silindi",
        );
    }

    /**
     * Yönetim arayüzünün adresi panelden değiştirilirse panelin cihaza
     * erişimi kesilir; istek buraya kadar gelmemeli.
     */
    private function guardManageable(SwitchIpAddress $address): void
    {
        abort_unless(
            $this->manageable->isManageable($address),
            Response::HTTP_FORBIDDEN,
            "{$address->interface_name} panelden değiştirilemez: ".$this->manageable->reason($address).'.',
        );
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.ip-addresses';
    }
}
