<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Firewall\FirewallFilterRequest;
use App\Http\Requests\Firewall\FirewallFilterSettingsRequest;
use App\Http\Requests\Firewall\FirewallPolicerRequest;
use App\Http\Requests\Firewall\FirewallTermRequest;
use App\Http\Requests\Firewall\TermOrderRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchFirewallFilter;
use App\Models\SwitchFirewallPolicer;
use App\Models\SwitchFirewallTerm;
use App\Services\ActivityRecorder;
use App\Services\Netconf\FirewallConfigurator;
use App\Services\Switching\DependencyGuard;
use App\Services\Switching\FirewallViewService;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Firewall filtreleri ve policer'ları.
 *
 * YAZMA YETKİSİ YALNIZCA YÖNETİCİDEDİR. Yanlış bir filtre bütün trafiği keser
 * ve RE filtresi söz konusuysa panelin cihaza erişimini de koparır; "configure"
 * yetkisi bunun için bilinçli olarak yetmez.
 *
 * Filtrenin tamamı her değişiklikte yeniden yazılır (sil + yaz, tek commit),
 * çünkü Junos'ta terim SIRASI anlamlıdır ve merge yeni terimi hep sona ekler.
 * Panelin tanımadığı satırlar bu sırada KAYBOLMAZ: ham hâlleriyle saklanıp
 * geri basılırlar (bkz. FirewallReader).
 */
class SwitchFirewallController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SwitchNavigationService $navigation,
        private FirewallViewService $view,
        private ActivityRecorder $activity,
        private FirewallConfigurator $filters,
        private DependencyGuard $dependencies,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchFirewall,switch', only: ['index']),
            // Görüntülemek yetki ister, YAZMAK yönetici olmayı gerektirir.
            new Middleware('can:createSwitchFirewall,switch', only: ['store', 'storeTerm', 'storePolicer']),
            new Middleware('can:updateSwitchFirewall,switch', only: ['update', 'updateTerm', 'reorderTerms', 'updatePolicer']),
            new Middleware('can:deleteSwitchFirewall,switch', only: ['destroy', 'destroyTerm', 'destroyPolicer']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        return view('switches.firewall', [
            'switch' => $switch,
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'firewall'),
            ...$this->view->screen($switch),
        ]);
    }

    public function store(FirewallFilterRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->filterName();

        return $this->queueWrite(
            $switch,
            FirewallConfigurator::class,
            'create',
            [$request->family(), $name, [$request->termAttributes()], $request->filterSettings()],
            "firewall filtresi {$name} eklendi",
        );
    }

    /**
     * Filtrenin KENDİ altındaki ayarlar; terimlerine dokunulmaz.
     *
     * "interface-specific" küçük bir satırdır ama arayüz başına sayaç
     * tutulmasını sağlar; panel onu okumasaydı her güncellemede sessizce
     * silinirdi.
     */
    public function update(
        FirewallFilterSettingsRequest $request,
        NetworkSwitch $switch,
        SwitchFirewallFilter $firewallFilter,
    ): RedirectResponse {
        return $this->queueWrite(
            $switch,
            FirewallConfigurator::class,
            'update',
            [$firewallFilter, $this->terms($firewallFilter), $request->filterSettings()],
            "firewall filtresi {$firewallFilter->name} ayarları güncellendi",
        );
    }

    public function destroy(NetworkSwitch $switch, SwitchFirewallFilter $firewallFilter): RedirectResponse
    {
        $name = $firewallFilter->name;

        return $this->queueWrite(
            $switch,
            FirewallConfigurator::class,
            'delete',
            [$firewallFilter],
            "firewall filtresi {$name} silindi",
        );
    }

    public function storeTerm(
        FirewallTermRequest $request,
        NetworkSwitch $switch,
        SwitchFirewallFilter $firewallFilter,
    ): RedirectResponse {
        $this->assertManageable($firewallFilter);

        $terms = $this->withTerm($this->terms($firewallFilter), $request, null);

        return $this->queueWrite(
            $switch,
            FirewallConfigurator::class,
            'update',
            [$firewallFilter, $terms],
            "{$firewallFilter->name} · terim eklendi",
        );
    }

    /**
     * Terimi yerinde günceller ve gerekirse SIRASINI değiştirir.
     *
     * Sıra bir tercih değil trafiğin kendisidir: Junos'ta ilk eşleşen terim
     * kazanır, arkasındakiler hiç çalışmaz. Bir "her şeyi düşür" teriminin
     * önüne geçmenin tek yolu budur.
     */
    public function updateTerm(
        FirewallTermRequest $request,
        NetworkSwitch $switch,
        SwitchFirewallFilter $firewallFilter,
        SwitchFirewallTerm $term,
    ): RedirectResponse {
        $this->assertManageable($firewallFilter);

        $terms = $this->withTerm($this->terms($firewallFilter), $request, $term->name);

        return $this->queueWrite(
            $switch,
            FirewallConfigurator::class,
            'update',
            [$firewallFilter, $terms],
            "{$firewallFilter->name} · {$term->name} terimi güncellendi",
        );
    }

    /**
     * Terimleri verilen sıraya dizer (sürükle-bırak).
     *
     * Gönderilen liste TAM olmalıdır: eksik bir liste, adı geçmeyen terimi
     * sessizce silerdi ve bir firewall filtresinde bunun bedeli kesintidir.
     */
    public function reorderTerms(
        TermOrderRequest $request,
        NetworkSwitch $switch,
        SwitchFirewallFilter $firewallFilter,
    ): RedirectResponse {
        $this->assertManageable($firewallFilter);

        $terms = $this->ordered($firewallFilter, $request->orderedUuids());

        return $this->queueWrite(
            $switch,
            FirewallConfigurator::class,
            'update',
            [$firewallFilter, $terms],
            "{$firewallFilter->name} · terim sırası değiştirildi",
        );
    }

    /**
     * Son terim silinemez: terimsiz filtre cihazda anlamsız bir kabuk bırakır
     * ve ona bağlı arayüzün trafiği tanımsız bir davranışa düşer.
     */
    public function destroyTerm(
        NetworkSwitch $switch,
        SwitchFirewallFilter $firewallFilter,
        SwitchFirewallTerm $term,
    ): RedirectResponse {
        $this->assertManageable($firewallFilter);

        abort_if($firewallFilter->terms->count() <= 1, 422, 'Filtrenin son terimi silinemez; filtreyi silin.');

        $terms = array_values(array_filter(
            $this->terms($firewallFilter),
            static fn (array $current): bool => $current['name'] !== $term->name,
        ));

        return $this->queueWrite(
            $switch,
            FirewallConfigurator::class,
            'update',
            [$firewallFilter, $terms],
            "{$firewallFilter->name} · {$term->name} terimi silindi",
        );
    }

    public function storePolicer(FirewallPolicerRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->policerName();

        return $this->queueWrite(
            $switch,
            FirewallConfigurator::class,
            'createPolicer',
            [$name, $request->policerSettings()],
            "firewall policer {$name} eklendi",
        );
    }

    public function updatePolicer(
        FirewallPolicerRequest $request,
        NetworkSwitch $switch,
        SwitchFirewallPolicer $firewallPolicer,
    ): RedirectResponse {
        return $this->queueWrite(
            $switch,
            FirewallConfigurator::class,
            'updatePolicer',
            [$firewallPolicer, $request->policerSettings()],
            "firewall policer {$firewallPolicer->name} güncellendi",
        );
    }

    /**
     * Kullanılan policer silinmez: tanımsız ada atıf commit'i düşürür.
     */
    public function destroyPolicer(NetworkSwitch $switch, SwitchFirewallPolicer $firewallPolicer): RedirectResponse
    {
        $bound = $this->dependencies->forPolicer($firewallPolicer);

        if ($bound !== []) {
            return $this->backToSwitch(
                $switch,
                $this->dependencies->message("Policer {$firewallPolicer->name}", $bound),
            );
        }

        $name = $firewallPolicer->name;

        return $this->queueWrite(
            $switch,
            FirewallConfigurator::class,
            'deletePolicer',
            [$firewallPolicer],
            "firewall policer {$name} silindi",
        );
    }

    /**
     * @param  list<string>  $uuids
     * @return list<array<string, mixed>>
     */
    private function ordered(SwitchFirewallFilter $filter, array $uuids): array
    {
        $current = $filter->terms->keyBy('uuid');

        abort_unless(
            count($uuids) === $current->count() && $current->keys()->diff($uuids)->isEmpty(),
            422,
            'Sıralama listesi filtrenin terimleriyle birebir eşleşmeli.',
        );

        $ordered = collect($uuids)->map(fn (string $uuid) => $current[$uuid]);

        return $ordered
            ->map(fn ($term): array => $this->termAttributes($term))
            ->all();
    }

    /**
     * Terimi listeden çıkarır ve istenen sıraya yerleştirir.
     *
     * Güncellemede eski kayıt önce ÇIKARILIR: adı değişmemişse aynı terim iki
     * kez yazılırdı, değişmişse eskisi listede kalırdı.
     *
     * @param  list<array<string, mixed>>  $terms
     * @return list<array<string, mixed>>
     */
    private function withTerm(array $terms, FirewallTermRequest $request, ?string $replacing): array
    {
        if ($replacing !== null) {
            $terms = array_values(array_filter(
                $terms,
                static fn (array $current): bool => $current['name'] !== $replacing,
            ));
        }

        $position = min($request->positionOr(count($terms)), count($terms));

        array_splice($terms, $position, 0, [$request->termAttributes()]);

        return $terms;
    }

    /**
     * HER filtre yönetilebilir: panelin tanımadığı satırlar ham hâlleriyle
     * korunup geri yazılır, dolayısıyla "yarısını anlayıp geri yazma"
     * tehlikesi yoktur.
     *
     * Kontrol yine de durur: envanterde false kalmış bir kayıt varsa ya da
     * ileride yeniden bir "temsil edilemez" hâl doğarsa, istek doğrudan da
     * gelebileceği için sunucu tarafında bir kapı bulunmalı.
     */
    private function assertManageable(SwitchFirewallFilter $filter): void
    {
        abort_unless($filter->is_manageable, 403, 'Bu filtre panelin temsil ettiği yapının dışında.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function terms(SwitchFirewallFilter $filter): array
    {
        return $filter->terms
            ->map(fn (SwitchFirewallTerm $term): array => $this->termAttributes($term))
            ->all();
    }

    /**
     * Kayıttaki terimi cihaza yazılacak biçime çevirir.
     *
     * Alanlar tek tek sayılmaz: modelin kendi alan listesi kullanılır, yoksa
     * yeni bir sütun eklendiğinde burada unutulur ve o değer cihaza SESSİZCE
     * yazılmaz — panelin gösterdiği ile cihazın gerçeği ayrılır.
     *
     * @return array<string, mixed>
     */
    private function termAttributes(SwitchFirewallTerm $term): array
    {
        return collect($term->getFillable())
            ->reject(fn (string $field): bool => in_array($field, ['switch_firewall_filter_id', 'position'], true))
            ->mapWithKeys(fn (string $field): array => [$field => $term->{$field}])
            ->all();
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.firewall';
    }
}
