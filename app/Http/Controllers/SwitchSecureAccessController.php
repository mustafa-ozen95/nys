<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Enums\SecureAccessAction;
use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Switching\SecureInterfaceRequest;
use App\Http\Requests\Switching\SecureVlanRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchSecureInterface;
use App\Models\SwitchSecureVlan;
use App\Services\ActivityRecorder;
use App\Services\Netconf\SecureAccessPortConfigurator;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * DHCP snooping ve port güvenliği (ethernet-switching-options
 * secure-access-port).
 *
 * Ekranın tamamı bir GÜVENLİK ekranıdır ve iki ayarı yanlış vermek koruma
 * yerine hasar üretir:
 *
 *  - "dhcp-trusted" erişim portuna verilirse o porta takılan herhangi bir
 *    cihaz ağın DHCP sunucusu gibi davranabilir; snooping'in koruması
 *    tamamen ortadan kalkar. Panel bunu formda söyler, onay penceresinde
 *    tekrarlar ve listede kırmızı rozetle işaretler.
 *  - Sınır eylemi "shutdown" portu HİZMET DIŞI bırakır ve port kendiliğinden
 *    geri gelmez.
 *
 * Ayarlar cihazdan okunur; ekranda panelde girilen değil CİHAZDAKİ gerçek
 * gösterilir.
 */
class SwitchSecureAccessController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SwitchNavigationService $navigation,
        private SecureAccessPortConfigurator $secure,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchSecureAccess,switch', only: ['index']),
            new Middleware('can:createSwitchSecureAccess,switch', only: ['vlanStore', 'interfaceStore']),
            new Middleware('can:updateSwitchSecureAccess,switch', only: ['vlanUpdate', 'interfaceUpdate']),
            new Middleware('can:deleteSwitchSecureAccess,switch', only: ['vlanDestroy', 'interfaceDestroy']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        $interfaces = $switch->secureInterfaces()->orderBy('interface_name')->get();

        $allKeyword = ($switch->schema_profile ?? JunosSchemaProfile::PreEls)
            ->dialect()
            ->supportsAllKeyword();

        return view('switches.secure-access', [
            'switch' => $switch,
            'allKeyword' => $allKeyword,
            'secureVlans' => $switch->secureVlans()->orderBy('vlan_name')->get(),
            'secureInterfaces' => $interfaces,
            'riskyInterfaces' => $this->riskyInterfaces($switch, $interfaces),

            /*
             * VLAN kuralı ada göre yazılır ve tanımsız ad commit'i düşürür;
             * listede yalnızca bu switchte TANIMLI VLAN'lar durur. "all"
             * sanal adını ELS ÖNCESİ kabul eder (test1'de ölçüldü), ELS ise
             * "Reserved word cannot be used" diyerek reddeder (test4).
             */
            'vlanOptions' => ($allKeyword ? ['all' => 'Bütün VLAN’lar (all)'] : []) + $switch->vlans()
                ->orderBy('vlan_tag')
                ->pluck('name', 'name')
                ->all(),

            /*
             * Yalnızca ANAHTARLAMA modundaki portlar sunulur: cihaz routed ve
             * yapılandırılmamış portta bu kuralı reddediyor.
             */
            'interfaceOptions' => ($allKeyword ? ['all' => 'Bütün portlar (all)'] : []) + $switch->ports()
                ->whereIn('port_mode', [PortMode::Access->value, PortMode::Trunk->value])
                ->orderBy('if_index')
                ->pluck('name', 'name')
                ->all(),

            'actionOptions' => SecureAccessAction::optionsFrom(
                ($switch->schema_profile ?? JunosSchemaProfile::PreEls)->dialect()->macLimitActions()
            ),
            'trustOptions' => [
                '' => 'Cihazın varsayılanı',
                '1' => 'Güvenilir — yalnızca uplink',
                '0' => 'Güvenilmez',
            ],

            /*
             * Ekran ARTIK ELS'te de yazılabilir: ayarlar orada üç ayrı
             * hiyerarşiye dağılmış durumda ve üçü de cihazda ölçüldü.
             * Eşlenmeyen alanlar ayrıca bildirilir; hangisi olduğunu lehçe
             * söyler, bu denetleyici değil.
             */
            'supported' => true,

            /*
             * ELS'te DHCP güveni ve izinli MAC listesi VLAN kapsamındadır;
             * o cihazlarda ekran hangi VLAN olduğunu sorar. Hangi alanların
             * böyle olduğunu LEHÇE söyler.
             */
            'vlanScopedFields' => $switch->schema_profile?->dialect()->vlanScopedSecureAccessFields() ?? [],
            'vlanOptions' => ['' => 'Seçilmedi'] + $switch->vlans()
                ->orderByRaw('vlan_tag is null')
                ->orderBy('vlan_tag')
                ->pluck('name', 'name')
                ->all(),

            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'secure-access'),
        ]);
    }

    public function vlanStore(SecureVlanRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->vlanName();

        return $this->queueWrite(
            $switch,
            SecureAccessPortConfigurator::class,
            'applyVlan',
            [$name, $request->settings()],
            "{$name} VLAN güvenliği eklendi",
        );
    }

    public function vlanUpdate(
        SecureVlanRequest $request,
        NetworkSwitch $switch,
        SwitchSecureVlan $secureVlan,
    ): RedirectResponse {
        $name = $secureVlan->vlan_name;

        return $this->queueWrite(
            $switch,
            SecureAccessPortConfigurator::class,
            'applyVlan',
            [$name, $request->settings()],
            "{$name} VLAN güvenliği güncellendi",
        );
    }

    public function vlanDestroy(NetworkSwitch $switch, SwitchSecureVlan $secureVlan): RedirectResponse
    {
        $name = $secureVlan->vlan_name;

        return $this->queueWrite(
            $switch,
            SecureAccessPortConfigurator::class,
            'deleteVlan',
            [$secureVlan],
            "{$name} VLAN güvenliği kaldırıldı",
        );
    }

    public function interfaceStore(SecureInterfaceRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->interfaceName();

        return $this->queueWrite(
            $switch,
            SecureAccessPortConfigurator::class,
            'applyInterface',
            [$name, $request->settings()],
            "{$name} port güvenliği eklendi".$this->shutdownNote($request),
        );
    }

    public function interfaceUpdate(
        SecureInterfaceRequest $request,
        NetworkSwitch $switch,
        SwitchSecureInterface $secureInterface,
    ): RedirectResponse {
        $name = $secureInterface->interface_name;

        return $this->queueWrite(
            $switch,
            SecureAccessPortConfigurator::class,
            'applyInterface',
            [$name, $request->settings()],
            "{$name} port güvenliği güncellendi".$this->shutdownNote($request),
        );
    }

    public function interfaceDestroy(NetworkSwitch $switch, SwitchSecureInterface $secureInterface): RedirectResponse
    {
        $name = $secureInterface->interface_name;

        return $this->queueWrite(
            $switch,
            SecureAccessPortConfigurator::class,
            'deleteInterface',
            [$secureInterface],
            "{$name} port güvenliği kaldırıldı",
        );
    }

    /**
     * Portu kapatan bir eylem yazıldıysa kullanıcı bunu SONUÇ mesajında da
     * görür. Onay penceresi işlemden önce uyarır; bu satır işlemden sonra
     * hatırlatır — sınırı aşan port artık sessizce ölecek.
     */
    private function shutdownNote(SecureInterfaceRequest $request): string
    {
        return $request->shutsPortDown() ? ' (sınır aşılınca port KAPANACAK)' : '';
    }

    /**
     * DHCP güvenilir yapılmış AMA uplink OLMAYAN arayüzler.
     *
     * Erişim portu güvenilir işaretlendiğinde snooping'in koruması ortadan
     * kalkar; "all" ise bütün portları kapsadığı için her zaman risklidir.
     * Hesap Blade'de değil burada yapılır: ekran hazır listeyi basar.
     *
     * @param  Collection<int, SwitchSecureInterface>  $interfaces
     * @return list<string>
     */
    private function riskyInterfaces(NetworkSwitch $switch, Collection $interfaces): array
    {
        $modes = $switch->ports()->pluck('port_mode', 'name');

        return $interfaces
            ->filter(fn (SwitchSecureInterface $interface): bool => $interface->dhcp_trusted === true
                && ($interface->interface_name === 'all'
                    || ($modes[$interface->port_name] ?? null) === PortMode::Access))
            ->pluck('uuid')
            ->all();
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.secure-access';
    }
}
