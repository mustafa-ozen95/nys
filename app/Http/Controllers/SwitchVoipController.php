<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PortMode;
use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Switching\VoipInterfaceRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchVoipInterface;
use App\Models\Vlan;
use App\Services\ActivityRecorder;
use App\Services\Netconf\VoipConfigurator;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * VoIP (ses) VLAN'ı.
 *
 * IP telefon ile ona bağlı bilgisayar tek kabloyu paylaşır: telefon ses
 * VLAN'ında ETİKETLİ, bilgisayar portun access VLAN'ında ETİKETSİZ gider.
 * Ayar cihazda vardı, panelde hiç yönetilmiyordu.
 *
 * Ayarlar cihazdan okunur; ekranda panelde girilen değer değil cihazın
 * gerçeği durur.
 */
class SwitchVoipController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SwitchNavigationService $navigation,
        private VoipConfigurator $voip,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchVoip,switch', only: ['index']),
            new Middleware('can:createSwitchVoip,switch', only: ['store']),
            new Middleware('can:updateSwitchVoip,switch', only: ['update']),
            new Middleware('can:deleteSwitchVoip,switch', only: ['destroy']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        $voipInterfaces = $switch->voipInterfaces()->orderBy('interface_name')->get();
        $vlans = $switch->vlans()->orderBy('name')->get();

        return view('switches.voip', [
            'switch' => $switch,
            'voipInterfaces' => $voipInterfaces,
            'interfaceOptions' => $this->interfaceOptions($switch, $voipInterfaces->pluck('interface_name')->all()),
            'vlanOptions' => $this->vlanOptions($vlans),
            /*
             * VLAN'ı olmayan switchte ses VLAN'ı tanımlanamaz: ad cihazda
             * tanımlı olmalı. Ekran bunu boş bir seçim kutusu göstermek
             * yerine açıkça söyler.
             */
            'hasVlans' => $vlans->isNotEmpty(),
            'forwardingClasses' => $this->forwardingClasses($voipInterfaces->pluck('forwarding_class')->all()),
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'voip'),
        ]);
    }

    public function store(VoipInterfaceRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->interfaceName();

        return $this->queueWrite(
            $switch,
            VoipConfigurator::class,
            'apply',
            [$name, $request->settings()],
            "{$name} ses VLAN’ı tanımlandı",
        );
    }

    public function update(
        VoipInterfaceRequest $request,
        NetworkSwitch $switch,
        SwitchVoipInterface $voipInterface,
    ): RedirectResponse {
        $name = $voipInterface->interface_name;

        return $this->queueWrite(
            $switch,
            VoipConfigurator::class,
            'apply',
            [$name, $request->settings()],
            "{$name} ses VLAN’ı güncellendi",
        );
    }

    public function destroy(NetworkSwitch $switch, SwitchVoipInterface $voipInterface): RedirectResponse
    {
        $name = $voipInterface->interface_name;

        return $this->queueWrite(
            $switch,
            VoipConfigurator::class,
            'delete',
            [$voipInterface],
            "{$name} ses VLAN’ı kaldırıldı",
        );
    }

    /**
     * Yalnızca ANAHTARLAMA ailesindeki portlar sunulur: cihaz aksini
     * "Interface ... does not have ethernet-switching family" ile reddediyor
     * (test1'de ölçüldü). Değerler Junos'un yazdığı gibi BİRİMLİDİR.
     *
     * "all" ve "access-ports" cihazın kabul ettiği sanal adlardır.
     *
     * Zaten ayarı olan arayüz listede DURMAZ: aynı arayüz iki kayıt olamaz
     * ve kullanıcıya seçtirip sonra "bu zaten alınmış" demek işe yaramaz —
     * mevcut kaydın kendi düzenleme penceresi var.
     *
     * @param  list<string>  $taken
     * @return array<string, string>
     */
    private function interfaceOptions(NetworkSwitch $switch, array $taken): array
    {
        /*
         * "all" SUNULMAZ: cihaz VoIP'te bu adı iki lehçede de reddediyor
         * (bkz. SwitchVoipInterface::VIRTUAL_INTERFACES). "access-ports"
         * ikisinde de kabul ediliyor.
         */
        $options = [
            'access-ports' => 'Bütün access portları (access-ports)',
        ];

        foreach ($switch->ports()
            ->whereIn('port_mode', [PortMode::Access->value, PortMode::Trunk->value])
            ->orderBy('if_index')
            ->pluck('name') as $name) {
            $options[$name.'.0'] = $name;
        }

        return array_diff_key($options, array_flip($taken));
    }

    /**
     * Ses VLAN'ı bu switchte TANIMLI olmalıdır; cihazda VLAN'ın kimliği
     * adıdır ve tanımsız ada atıf commit'i düşürür.
     *
     * @param  Collection<int, Vlan>  $vlans
     * @return array<string, string>
     */
    private function vlanOptions(Collection $vlans): array
    {
        return ['' => 'Seçilmedi'] + $vlans
            ->mapWithKeys(fn (Vlan $vlan): array => [$vlan->name => $vlan->name.' ('.($vlan->vlan_tag ?? 'etiketsiz').')'])
            ->all();
    }

    /**
     * Junos'un varsayılan sınıfları + cihazda GERÇEKTEN kullanılanlar.
     *
     * Cihazda kendi sınıfını tanımlamış bir kurulumda okunan değer listede
     * olmasaydı, düzenleme penceresi onu sessizce başka bir sınıfa çevirirdi.
     *
     * @param  list<string|null>  $inUse
     * @return array<string, string>
     */
    private function forwardingClasses(array $inUse): array
    {
        $options = ['' => 'Belirtilmemiş (cihaz kendi sınıfını uygular)']
            + config('switching.voip_forwarding_classes');

        foreach (array_filter($inUse) as $class) {
            $options[$class] ??= $class;
        }

        return $options;
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.voip';
    }
}
