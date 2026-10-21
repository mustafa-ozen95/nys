<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FirewallFamily;
use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Switching\SwitchPortRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\SwitchWriteJob;
use App\Models\Vlan;
use App\Services\ActivityRecorder;
use App\Services\Capabilities\DeviceCapabilities;
use App\Services\Netconf\PortConfigurator;
use App\Services\Switching\SwitchAddressCandidateService;
use App\Services\Switching\SwitchNavigationService;
use App\Services\Switching\SwitchPortService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class SwitchPortController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SwitchPortService $ports,
        private SwitchNavigationService $navigation,
        private ActivityRecorder $activity,
        private PortConfigurator $configurator,
        private SwitchAddressCandidateService $candidates,
        private DeviceCapabilities $capabilities,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchPorts,switch', only: ['index']),
            // Port ayarları cihaza yazılır; "update" değil "configure" ister.
            new Middleware('can:updateSwitchPorts,switch', only: ['update']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        $filter = $request->string('q')->toString() ?: null;
        // Genel bakıştan bir porta tıklanmışsa o port seçili açılır.
        $selectedPort = $request->string('port')->toString() ?: null;

        /*
         * Portlar ÖNCE hazırlanır: hız seçenekleri, cihazda zaten yazılı
         * değerleri bu listeden okur ve ayrı bir sorgu açmaz.
         */
        $portlar = $this->ports->forNetworkSwitch($switch, $filter, $selectedPort);
        $portlar['ports'] = $this->withSpeedOptions($switch, $portlar['ports']);
        $portlar['ports'] = $this->withPendingWrites($switch, $portlar['ports']);

        return view('switches.ports', [
            'switch' => $switch,
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'ports'),
            'query' => $filter ?? '',
            'ranges' => [
                '1h' => '1 saat', '6h' => '6 saat', '24h' => '24 saat',
                '7d' => '7 gün', '30d' => '30 gün',
            ],
            'vlans' => $vlans = $switch->vlans()->orderBy('vlan_tag')->get(),
            'untaggedOptions' => ['' => 'Yok'] + $vlans->mapWithKeys(
                fn (Vlan $vlan): array => [$vlan->uuid => $vlan->label]
            )->all(),
            // Access portta tek VLAN seçilir; boş seçenek portu VLAN'sız
            // bırakmak içindir (kullanılmayan port).
            'vlanOptions' => ['' => 'Yok'] + $vlans->mapWithKeys(
                fn (Vlan $vlan): array => [$vlan->uuid => $vlan->label]
            )->all(),
            'modeOptions' => $this->modeOptions(),

            /*
             * Yönlendirme modunda arayüze bağlanabilecek filtreler. Yalnızca
             * "inet" ailesindekiler: cihaz ethernet-switching ailesinde bu
             * bağlama biçimini kabul etmiyor.
             */
            /*
             * Adres BURADAN da verilebilsin: portu yönlendirmeye çevirip
             * başka bir ekrana gitmek gereksiz bir adım. Seçenekler IP
             * adresleri ekranıyla aynı kaynaktan gelir.
             */
            'addressOptions' => $this->candidates->groupedOptions($switch),

            'filterOptions' => ['' => 'Yok'] + $switch->firewallFilters()
                ->where('family', FirewallFamily::Inet)
                ->orderBy('name')
                ->pluck('name', 'name')
                ->all(),
            'duplexOptions' => $this->duplexOptions(),
            'halfDuplexSpeeds' => (array) config('netconf.half_duplex_speeds'),
        ] + $portlar);
    }

    /**
     * Port ayarlarını CİHAZA yazar ve sonucu cihazdan geri okur.
     *
     * Envanter kaydı elle güncellenmez: ne yazıldığı değil, cihazda ne olduğu
     * gösterilir. Cihaz bir satırı reddederse ekranda eski değer kalır.
     */
    public function update(SwitchPortRequest $request, NetworkSwitch $switch, SwitchPort $port): RedirectResponse
    {
        abort_unless($port->switch_id === $switch->id, 404);

        /*
         * Yazma KUYRUĞA alınır: bir port değişikliği ELS switchte 45-60
         * saniye sürüyor ve isteği o kadar tutuyordu. Ayarlar burada, istek
         * hayattayken hesaplanır; cihaza giden iş yalnızca sonucu taşır.
         */
        $job = $this->queueDeviceWrite(
            $switch,
            PortConfigurator::class,
            'apply',
            [$port, $this->settings($request, $switch, $port)],
            "{$port->name} portu yazıldı",
        );

        return $this->back($switch, $port, "{$port->name}: cihaza yazılıyor.")
            ->with('write_job_url', route('switches.write-jobs.show', [$switch, $job]));
    }

    /**
     * Formdaki uuid'ler cihazın anladığı VLAN adlarına çevrilir; Junos
     * üyelikleri adla tutar.
     *
     * @return array<string, mixed>
     */
    private function settings(SwitchPortRequest $request, NetworkSwitch $switch, SwitchPort $port): array
    {
        $secilen = $switch->vlans()->whereIn('uuid', $request->input('vlans', []))->get();
        $names = $secilen->pluck('name', 'uuid');
        $speed = $request->string('speed_config')->toString() ?: null;
        $native = $request->isAccess() ? null : $this->nativeVlan($request, $switch);

        /*
         * ANAHTAR YALNIZCA ALAN GELDİYSE KONUR.
         *
         * Bu ekranda birçok alanda "gönderilmedi" cihazdaki satırın SİLİNMESİ
         * demektir: boş açıklama açıklamayı siler, boş hız portu otomatik
         * anlaşmaya döndürür, boş MTU satırı kaldırır. Basit modda gizlenen
         * alan da isteğe hiç girmez — anahtar koşulsuz konsaydı, kullanıcının
         * GÖRMEDİĞİ bir alan her kayıtta cihazdan silinirdi.
         *
         * Gelişmiş modda alan görünür ve BOŞ gönderilir; o zaman anahtar
         * konur ve silme bilinçli olur. Fark tam olarak budur.
         */
        $settings = [
            'enabled' => $request->boolean('enabled'),
            'mode' => $request->enum('port_mode', PortMode::class),
            'vlans' => array_values($names->all()),
            // Access portta tek üye zaten etiketsizdir; trunk portta etiketsiz
            // VLAN native-vlan-id ile arayüz seviyesinde işaretlenir.
            'untagged' => $request->isAccess() ? ($names->first() ?: null) : $native?->name,
            'native_vlan_tag' => $native?->vlan_tag,
        ];

        if ($request->has('description')) {
            $settings['description'] = $request->string('description')->toString() ?: null;
        }

        // Hız ile auto-negotiation aynı ayardır: hız boşsa otomatik.
        if ($request->has('speed_config')) {
            $settings['speed'] = $speed;
            $settings['autonegotiation'] = $speed === null;
        }

        // Boş bırakmak satırı siler: cihaz duplex'i kendi seçer.
        if ($request->has('duplex_config')) {
            $settings['duplex'] = $request->string('duplex_config')->toString() ?: null;
        }

        /*
         * Filtre bağlaması yalnızca YÖNLENDİRME modunda anlamlıdır ve yalnızca
         * o zaman gönderilir. Anahtar hiç konmazsa cihazdaki satıra
         * dokunulmaz — access/trunk'a geçerken aile zaten tümüyle kalkıyor.
         */
        if ($request->isRouted()) {
            $settings['input_filter'] = $request->string('input_filter')->toString() ?: null;
            $settings['output_filter'] = $request->string('output_filter')->toString() ?: null;

            // Adres buradan da verilebilir; aynı commit'te yazılır.
            $settings['ip_address'] = $request->ipCidr();
        }

        /*
         * MTU yalnızca ae üyesi OLMAYAN portlarda yazılır; cihaz üyede
         * reddediyor. Anahtar hiç konmazsa cihazdaki satıra dokunulmaz.
         */
        if ($port->aggregate_port_id === null && $request->has('mtu')) {
            $settings['mtu'] = filled($request->input('mtu')) ? $request->integer('mtu') : null;
        }

        // PoE donanımı olmayan porta PoE satırı yazılmaz.
        if ($port->is_poe_enabled !== null && $request->has('is_poe_enabled')) {
            $settings['poe'] = $request->boolean('is_poe_enabled');
        }

        return $settings;
    }

    /**
     * Trunk portun etiketsiz (native) VLAN'ı. Etiketi cihaza native-vlan-id
     * olarak yazılır, adı üye listesinde durur.
     */
    private function nativeVlan(SwitchPortRequest $request, NetworkSwitch $switch): ?Vlan
    {
        $uuid = $request->string('untagged_vlan')->toString() ?: null;

        return $uuid === null ? null : $switch->vlans()->where('uuid', $uuid)->first();
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.ports';
    }

    private function back(NetworkSwitch $switch, SwitchPort $port, string $status): RedirectResponse
    {
        return redirect()
            ->route('switches.ports', ['switch' => $switch, 'port' => $port->name])
            ->with('status', $status);
    }

    /**
     * @return array<string, string>
     */
    private function modeOptions(): array
    {
        /*
         * ÜÇ mod da yazılabilir. "routed" uzun süre listede yoktu ve tek
         * çıkış IP adresleri ekranından adres vermekti — adresin Adresleme'de
         * bir bloğu yoksa o yol da kapalıydı ve anahtarlamaya çevrilen bir
         * port panelden bir daha yönlendirmeye döndürülemiyordu.
         *
         * Adressiz "family inet" cihazca kabul ediliyor (test1, commit
         * check): port yönlendirmeye döner, adres sonra verilir.
         *
         * "unconfigured" bir seçim değil, hiç yapılandırılmamış olma hâlidir.
         */
        return collect([PortMode::Access, PortMode::Trunk, PortMode::Routed])
            ->mapWithKeys(fn (PortMode $mode): array => [$mode->value => $mode->label()])
            ->all();
    }

    /**
     * Modal açılırken seçili gelecek mod.
     *
     * Yapılandırılmamış portta hiçbir şey seçili gelmez: o bir MOD değil,
     * hiç ayarlanmamış olma hâlidir. Listede karşılığı olmadığı için tarayıcı
     * ilk seçeneğe ("Access") düşüyordu ve kullanıcı farkında olmadan portu
     * anahtarlamaya çeviriyordu.
     */
    private function selectedMode(SwitchPort $port): ?string
    {
        return in_array($port->port_mode, [PortMode::Access, PortMode::Trunk, PortMode::Routed], true)
            ? $port->port_mode->value
            : null;
    }

    /**
     * Yalnızca CİHAZIN kabul ettiği hızlar.
     *
     * Liste iki kez daraltılır. Önce lehçe: hangi hızlar bu Junos ailesinde
     * yazılabilir. Sonra ölçüm: EX2300'de 1000BASE-T zorla ayarlanamıyor
     * ("invalid value", test4'te ölçüldü) ve o değer listede DURMAZ.
     *
     * Hiç ölçülmemiş cihazda liste daralmaz — sonda çalışmadı diye çalışan
     * bir seçeneğin kaybolması yanlış değeri denemekten kötüdür.
     *
     * @return array<string, string>
     */
    private function speedOptions(NetworkSwitch $switch, SwitchPort $port): array
    {
        $lehce = ($switch->schema_profile ?? JunosSchemaProfile::PreEls)->dialect();

        $hizlar = collect($lehce->speedValues())
            ->keys()
            ->mapWithKeys(fn (string $mbps): array => [$mbps => $mbps.' Mbps'])
            ->all();

        $daraltilmis = $this->capabilities->filterSpeeds($switch, (string) $port->name, $hizlar);

        /*
         * CİHAZDA ZATEN YAZILI bir hız listeden düşerse seçili değer boşa
         * düşer ve kaydeden kullanıcı farkında olmadan otomatik anlaşmaya
         * geçerdi. Daraltma bir seçeneği gizleyebilir, var olan bir ayarı
         * silemez.
         */
        $yazili = (string) ($port->speed_config ?? '');

        if ($yazili !== '' && ! array_key_exists($yazili, $daraltilmis)) {
            $daraltilmis[$yazili] = $yazili.' Mbps (cihazda tanımlı)';
        }

        return ['' => 'Otomatik'] + $daraltilmis;
    }

    /**
     * Üzerinde İŞLEM SÜREN portları işaretler.
     *
     * Yazma kuyruğa alındığı için ekran hemen dönüyor; kullanıcı aynı porta
     * ikinci bir değişiklik göndermeye çalışabilir. İkincisi BİRİNCİNİN
     * SONUCUNU GÖRMEDEN hazırlanmış olur ve bu ekranda "gönderilmeyen alan
     * silinir" kuralı geçerli — birinci yazmanın etkisi sessizce geri
     * alınabilirdi.
     *
     * Sunucu da reddediyor (bkz. RespondsToDeviceWrite::guardPendingWrite);
     * buradaki işaret, kullanıcının reddedilecek bir formu doldurmasını
     * baştan engeller.
     *
     * TEK sorgu: port başına sorulsaydı ekranın sorgu sayısı kilidi düşerdi.
     *
     * @param  Collection<int, array<string, mixed>>  $ports
     * @return Collection<int, array<string, mixed>>
     */
    private function withPendingWrites(NetworkSwitch $switch, Collection $ports): Collection
    {
        $suren = SwitchWriteJob::query()
            ->pending()
            ->where('switch_id', $switch->id)
            ->where('subject_type', (new SwitchPort)->getMorphClass())
            ->pluck('summary', 'subject_id');

        return $ports->map(fn (array $port): array => $port + [
            'pending_write' => $suren[$port['model']->id] ?? null,
        ]);
    }

    /**
     * Her porta KENDİ hız seçeneklerini ekler.
     *
     * Kısıt cihaz genelinde değil PIC başına: aynı cihazda yerleşik bakır
     * port 1 Gbps'i zorla kabul etmezken SFP yuvası kabul ediyor (test4'te
     * ölçüldü). Tek ortak liste, uplinklerde geçerli olan seçeneği gizlerdi.
     *
     * @param  Collection<int, array{model: SwitchPort}>  $ports
     * @return Collection<int, array<string, mixed>>
     */
    private function withSpeedOptions(NetworkSwitch $switch, Collection $ports): Collection
    {
        return $ports->map(function (array $port) use ($switch): array {
            $secenekler = $this->speedOptions($switch, $port['model']);

            return $port + [
                'speed_options' => $secenekler,
                'speed_hint' => $this->speedHint($secenekler),
            ];
        });
    }

    /**
     * Hız alanının ipucu.
     *
     * Seçenek listesi cihazın ÖLÇÜLMÜŞ kabul ettikleriyle sınırlı. Eksik bir
     * hız açıklanmazsa liste "saçma" görünür: portu 1 Gbps çalışan bir
     * cihazda 1000 seçeneğinin olmaması hata sanılır. Oysa 1000BASE-T
     * bakırda ZORLA ayarlanamaz; otomatik anlaşmayla elde edilir ve
     * EX2300 bu yüzden "speed 1g"yi reddediyor (test4'te ölçüldü).
     *
     * @param  array<string, string>  $options
     */
    private function speedHint(array $options): string
    {
        $temel = 'Otomatik seçilirse auto-negotiation kullanılır.';

        if (array_key_exists('1000', $options)) {
            return $temel;
        }

        return $temel.' Bu port 1000 Mbps’i zorla kabul etmiyor;'
            .' gigabit bağlantı otomatik anlaşmayla kurulur.';
    }

    /**
     * Duplex seçenekleri.
     *
     * Boş bırakmak "cihaz kendi seçsin" demektir. Half yalnızca 10/100 Mbps
     * ile geçerlidir; arayüz onu o hızlar dışında seçtirmez, sunucu da
     * reddeder (bkz. SwitchPortRequest).
     *
     * @return array<string, string>
     */
    private function duplexOptions(): array
    {
        return ['' => 'Otomatik'] + collect(config('netconf.link_modes'))
            ->keys()
            ->mapWithKeys(fn (string $key): array => [
                $key => $key === 'full' ? 'Full duplex' : 'Half duplex (yalnızca 10/100)',
            ])
            ->all();
    }
}
