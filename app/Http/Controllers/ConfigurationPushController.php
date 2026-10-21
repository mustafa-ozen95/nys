<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityChannel;
use App\Enums\AddressPurpose;
use App\Enums\CredentialProtocol;
use App\Http\Requests\Provisioning\ConfigurationPushRequest;
use App\Models\ConfigurationPush;
use App\Models\NetworkSwitch;
use App\Services\ActivityRecorder;
use App\Services\Addressing\AddressCandidateService;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\NetconfTarget;
use App\Services\Provisioning\ConfigurationPusher;
use App\Support\Netconf\ConfigurationRedactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Cihaza tam config yükleme.
 *
 * Hedef envanterdeki bir switch olabilir; yalnızca yönetim erişim bilgisine
 * sahip olduğumuz dış bir cihaz da olabilir. Kaynak elle yapıştırılan metin
 * ya da panelin ürettiği şablon çıktısıdır.
 *
 * Yetkilendirme hedefe bağlıdır ve bu yüzden ara katmanda DEĞİL burada
 * yapılır: envanterdeki cihazda switch başına verilebilen "provision"
 * yetkisi aranır, envanterde olmayan cihazda ise yönetici olmak gerekir —
 * kayıt yoksa kayıt bazlı yetki de yoktur.
 */
class ConfigurationPushController extends Controller
{
    public function __construct(
        private ConfigurationPusher $pusher,
        private ActivityRecorder $activity,
        private AddressCandidateService $candidates,
    ) {}

    public function create(): View
    {
        $switches = NetworkSwitch::query()
            ->orderBy('hostname')
            ->get()
            ->filter(fn (NetworkSwitch $switch): bool => Gate::allows('provision', $switch));

        $disCihaz = Gate::allows('provisionExternalDevice');

        /*
         * HİÇBİR HEDEFİ OLMAYAN EKRANI AÇMAZ.
         *
         * Yazma ucu (store) zaten hedef başına yetki soruyor, ama EKRANIN
         * KENDİSİ açık kalsaydı hiçbir izni olmayan bir kullanıcı burayı
         * açtığında boş bir cihaz listesi görür ama yönetim adresi
         * adaylarını görürdü — yani ağın adresleme planını.
         *
         * Yetkiyi listeyi süzerek uygulamak yetmiyor: sayfada süzülmeyen
         * başka veri de var. Hedefi olmayan için bu sayfanın hiçbir işi yok.
         */
        abort_if($switches->isEmpty() && ! $disCihaz, 403);

        return view('provisioning.create', [
            'switches' => $switches->pluck('hostname', 'uuid')->all(),
            'canUseExternal' => $disCihaz,

            /*
             * Yönetim adresi ve ağ geçidi Adresleme'den seçilir. Elle giriş
             * açıktır: kurulan cihaz henüz envanterde olmayabilir.
             */
            'managementOptions' => $this->candidates->hostOptions([AddressPurpose::Management]),
            // Geçmiş de süzülür: ekranın cihaz listesi gibi.
            'history' => ConfigurationPush::query()
                ->visibleTo(auth()->user())
                ->with(['user', 'switch'])
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function store(ConfigurationPushRequest $request): RedirectResponse
    {
        $target = $this->resolveTarget($request);

        try {
            $configuration = $this->configurationFor($request, $target);

            if (! $request->appliesImmediately()) {
                $onizleme = $this->pusher->preview($target, $configuration, (string) $request->input('mode'));

                return back()
                    ->withInput()
                    /*
                     * Fark EKRANA maskelenmiş gider: cihazın kendi
                     * yapılandırması sır taşır ($9$ anahtarlar, community
                     * adları) ve Junos'un $9$ biçimi geri çevrilebilir.
                     * Kullanıcının YAZDIĞI metin ("configuration") olduğu
                     * gibi kalır — onu zaten kendisi girdi.
                     */
                    ->with('preview', [
                        ...$onizleme,
                        'diff' => ConfigurationRedactor::apply($onizleme['diff']),
                        'configuration' => $configuration,
                    ]);
            }

            $push = $this->pusher->push(
                $target,
                $configuration,
                (string) $request->input('mode'),
                (string) $request->input('source'),
                $request->user(),
            );
        } catch (NetconfException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $this->activity->record(
            $push->switch,
            'provisioned',
            "Config yüklendi: {$target->label} ({$push->mode})",
            ActivityChannel::Config,
        );

        $this->saveToInventory($request, $target);

        return back()->with('status', $push->committed
            ? "{$target->label} cihazına yazıldı ve commit onaylandı."
            : "{$target->label} zaten istenen durumdaydı; commit atılmadı.");
    }

    /**
     * Hedefi çözer ve yetkiyi orada dener.
     */
    private function resolveTarget(ConfigurationPushRequest $request): NetconfTarget
    {
        $switch = $request->targetSwitch();

        if ($switch !== null) {
            Gate::authorize('provision', $switch);

            return NetconfTarget::fromSwitch($switch);
        }

        /*
         * Envanterde olmayan cihaz: kayıt bazlı yetki verilemeyeceği için
         * ayrı bir yetenek sorulur. Kural burada değil Gate'te tanımlıdır.
         */
        Gate::authorize('provisionExternalDevice');

        return new NetconfTarget(
            host: (string) $request->input('host'),
            port: (int) ($request->input('port') ?: config('netconf.port')),
            username: (string) $request->input('username'),
            password: (string) $request->input('password'),
            label: (string) $request->input('host'),
        );
    }

    /**
     * @throws NetconfException
     */
    private function configurationFor(ConfigurationPushRequest $request, NetconfTarget $target): string
    {
        if ($request->input('source') === 'manual') {
            return (string) $request->input('body');
        }

        return $this->pusher->fromTemplate($target, $request->templateInput())['configuration'];
    }

    /**
     * İstenirse dış cihaz envantere alınır.
     *
     * Parola yalnızca burada saklanır ve şifreli tutulur; kutu
     * işaretlenmediyse hiçbir yere yazılmaz.
     */
    private function saveToInventory(ConfigurationPushRequest $request, NetconfTarget $target): void
    {
        if ($target->switchId !== null || ! $request->boolean('save_to_inventory')) {
            return;
        }

        Gate::authorize('create', NetworkSwitch::class);

        $switch = NetworkSwitch::query()->firstOrCreate(
            ['management_ip' => $target->host],
            ['hostname' => (string) ($request->input('hostname') ?: $target->host)],
        );

        $switch->credentials()->firstOrCreate(
            ['protocol' => CredentialProtocol::Netconf],
            ['username' => $target->username, 'password' => $target->password, 'port' => $target->port],
        );

        $this->activity->created($switch, "Cihaz envantere alındı: {$switch->hostname}");
    }
}
