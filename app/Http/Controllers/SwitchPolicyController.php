<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Firewall\TermOrderRequest;
use App\Http\Requests\Policy\PolicyDefaultsRequest;
use App\Http\Requests\Policy\PolicyStatementRequest;
use App\Http\Requests\Policy\PolicyTermRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchPolicyStatement;
use App\Models\SwitchPolicyTerm;
use App\Services\ActivityRecorder;
use App\Services\Bgp\PolicyViewService;
use App\Services\Netconf\PolicyStatementConfigurator;
use App\Services\Switching\DependencyGuard;
use App\Services\Switching\SwitchNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Routing politikaları: policy-statement'lar ve terimleri.
 *
 * Politikanın tamamı her değişiklikte yeniden yazılır (sil + yaz, tek commit)
 * çünkü Junos'ta terim SIRASI anlamlıdır ve merge yeni terimi hep sona ekler.
 * Bu yüzden her uç, terim listesinin TAMAMINI hazırlayıp yazıcıya verir.
 */
class SwitchPolicyController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private SwitchNavigationService $navigation,
        private PolicyViewService $view,
        private ActivityRecorder $activity,
        private PolicyStatementConfigurator $policies,
        private DependencyGuard $dependencies,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewSwitchPolicies,switch', only: ['index']),
            new Middleware('can:createSwitchPolicies,switch', only: ['store', 'storeTerm']),
            new Middleware('can:updateSwitchPolicies,switch', only: ['updateTerm', 'reorderTerms', 'updateDefaults']),
            new Middleware('can:deleteSwitchPolicies,switch', only: ['destroy', 'destroyTerm']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        return view('switches.policies', [
            'switch' => $switch,
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'policies'),
            ...$this->view->screen($switch),
        ]);
    }

    /**
     * Politika İLK TERİMİYLE yaratılır: terimsiz politika cihazda sessizce
     * yok sayılıyor ("statement has no contents; ignored").
     */
    public function store(PolicyStatementRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->statementName();

        return $this->queueWrite(
            $switch,
            PolicyStatementConfigurator::class,
            'create',
            [$name, [$request->termAttributes()], $request->defaults()],
            "politika {$name} eklendi",
        );
    }

    public function destroy(NetworkSwitch $switch, SwitchPolicyStatement $policyStatement): RedirectResponse
    {
        $bagli = $this->dependencies->forPolicy($policyStatement);

        // Politikayı içeri alan ya da dışarı veren bir BGP grubu varsa cihaz
        // commit'i reddeder: "Policy referenced but not defined".
        if ($bagli !== []) {
            return back()->with(
                'error',
                $this->dependencies->message("Politika {$policyStatement->name}", $bagli),
            );
        }

        $name = $policyStatement->name;

        return $this->queueWrite(
            $switch,
            PolicyStatementConfigurator::class,
            'delete',
            [$policyStatement],
            "politika {$name} silindi",
        );
    }

    public function storeTerm(
        PolicyTermRequest $request,
        NetworkSwitch $switch,
        SwitchPolicyStatement $policyStatement,
    ): RedirectResponse {
        $this->assertManageable($policyStatement);

        $terms = $this->withTerm($this->terms($policyStatement), $request, null);

        return $this->queueWrite(
            $switch,
            PolicyStatementConfigurator::class,
            'update',
            [$policyStatement, $terms, $this->defaults($policyStatement)],
            "{$policyStatement->name} · terim eklendi",
        );
    }

    /**
     * Terimi yerinde günceller ve gerekirse SIRASINI değiştirir.
     *
     * Junos'ta ilk eşleşen terim kazanır: "her şeyi reddet" teriminin önüne
     * geçmenin tek yolu sırayı değiştirmektir.
     */
    public function updateTerm(
        PolicyTermRequest $request,
        NetworkSwitch $switch,
        SwitchPolicyStatement $policyStatement,
        SwitchPolicyTerm $term,
    ): RedirectResponse {
        $this->assertManageable($policyStatement);

        $terms = $this->withTerm($this->terms($policyStatement), $request, $term->name);

        return $this->queueWrite(
            $switch,
            PolicyStatementConfigurator::class,
            'update',
            [$policyStatement, $terms, $this->defaults($policyStatement)],
            "{$policyStatement->name} · {$term->name} terimi güncellendi",
        );
    }

    /**
     * Terimleri verilen sıraya dizer (sürükle-bırak).
     *
     * Gönderilen liste TAM olmalıdır; eksik bir liste adı geçmeyen terimi
     * sessizce silerdi.
     */
    public function reorderTerms(
        TermOrderRequest $request,
        NetworkSwitch $switch,
        SwitchPolicyStatement $policyStatement,
    ): RedirectResponse {
        $this->assertManageable($policyStatement);

        $terms = $this->ordered($policyStatement, $request->orderedUuids());

        return $this->queueWrite(
            $switch,
            PolicyStatementConfigurator::class,
            'update',
            [$policyStatement, $terms, $this->defaults($policyStatement)],
            "{$policyStatement->name} · terim sırası değiştirildi",
        );
    }

    /**
     * @param  list<string>  $uuids
     * @return list<array<string, mixed>>
     */
    private function ordered(SwitchPolicyStatement $policy, array $uuids): array
    {
        $mevcut = $policy->terms->keyBy('uuid');

        abort_unless(
            count($uuids) === $mevcut->count() && $mevcut->keys()->diff($uuids)->isEmpty(),
            422,
            'Sıralama listesi politikanın terimleriyle birebir eşleşmeli.',
        );

        return collect($uuids)
            ->map(fn (string $uuid): array => $this->termAttributes($mevcut[$uuid]))
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
    private function withTerm(array $terms, PolicyTermRequest $request, ?string $replacing): array
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
     * Politikanın VARSAYILAN DAVRANIŞI: terimlerden sonra gelen from/then.
     *
     * Junos'ta bu satır politikanın son sözüdür ("then reject;") ve bütün
     * BGP süzgeçlerinin standart kapanışıdır. Terimler değişmez; politika
     * yine silinip aynı terimlerle yeniden yazılır (tek commit).
     */
    public function updateDefaults(
        PolicyDefaultsRequest $request,
        NetworkSwitch $switch,
        SwitchPolicyStatement $policyStatement,
    ): RedirectResponse {
        $this->assertManageable($policyStatement);

        return $this->queueWrite(
            $switch,
            PolicyStatementConfigurator::class,
            'update',
            [$policyStatement, $this->terms($policyStatement), $request->defaults()],
            "{$policyStatement->name} · varsayılan davranış güncellendi",
        );
    }

    /**
     * Son terim silinemez: terimsiz kalan politika cihazda yok sayılır ve
     * ona dayanan BGP grubu sessizce politikasız kalırdı. Politikanın tamamı
     * silinmek isteniyorsa ayrı bir uç var.
     */
    public function destroyTerm(
        NetworkSwitch $switch,
        SwitchPolicyStatement $policyStatement,
        SwitchPolicyTerm $term,
    ): RedirectResponse {
        $this->assertManageable($policyStatement);

        abort_if($policyStatement->terms->count() <= 1, 422, 'Politikanın son terimi silinemez; politikayı silin.');

        $terms = array_values(array_filter(
            $this->terms($policyStatement),
            static fn (array $current): bool => $current['name'] !== $term->name,
        ));

        return $this->queueWrite(
            $switch,
            PolicyStatementConfigurator::class,
            'update',
            [$policyStatement, $terms, $this->defaults($policyStatement)],
            "{$policyStatement->name} · {$term->name} terimi silindi",
        );
    }

    /**
     * Her politika yönetilebilir: modellenmeyen satırlar ham XML olarak
     * korunup geri yazılır ve bayrak HEP true'dur. Kontrol yine de durur:
     * envanterde false kalmış bir satır varsa yazma engellenir ve sessizce
     * yanlış bir belge üretilmez.
     */
    private function assertManageable(SwitchPolicyStatement $policyStatement): void
    {
        abort_unless($policyStatement->is_manageable, 403, 'Bu politika panelin temsil ettiği yapının dışında.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function terms(SwitchPolicyStatement $policyStatement): array
    {
        return $policyStatement->terms
            ->map(fn (SwitchPolicyTerm $term): array => $this->termAttributes($term))
            ->all();
    }

    /**
     * Envanterdeki terimin cihaza yazılacak hâli.
     *
     * Panelin MODELLEMEDİĞİ satırlar da taşınır (residual_xml, from ve then
     * içindekiler dâhil): politika silinip yeniden yazıldığı için, buradan
     * düşen satır cihazda da kaybolurdu.
     *
     * @return array<string, mixed>
     */
    private function termAttributes(SwitchPolicyTerm $term): array
    {
        return [
            'name' => $term->name,
            'match_prefix_lists' => $term->match_prefix_lists ?? [],
            'match_type' => $term->match_type,
            'match_conditions' => $term->match_conditions,
            'action' => $term->action,
            'then_actions' => $term->then_actions,
            'residual_xml' => $term->residual_xml,
        ];
    }

    /**
     * Politikanın KENDİ from/then'i ve tanınmayan satırları.
     *
     * Her yazma yolu bunu taşımak ZORUNDA: güncelleme politikayı silip
     * yeniden yazıyor ve "then reject;" satırı taşınmazsa politika
     * "gerisini reddet"ten "gerisini de al"a dönüyor.
     *
     * @return array<string, mixed>
     */
    private function defaults(SwitchPolicyStatement $statement): array
    {
        return [
            'match' => $statement->default_match,
            'then' => $statement->default_then,
            'residual_xml' => $statement->residual_xml,
        ];
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.policies';
    }
}
