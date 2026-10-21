<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AddressConnectionType;
use App\Enums\AddressPurpose;
use App\Enums\SwitchRole;
use App\Http\Requests\Addressing\AddressBlockRequest;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchPrefixList;
use App\Services\ActivityRecorder;
use App\Services\Addressing\AddressAllocationService;
use App\Services\Addressing\AddressBlockService;
use App\Services\Addressing\AddressLayoutService;
use App\Services\Addressing\InterfaceAssignmentService;
use App\Services\Addressing\PrefixListAssignmentService;
use App\Services\Addressing\RouteAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AddressBlockController extends Controller implements HasMiddleware
{
    public function __construct(
        private AddressBlockService $blocks,
        private AddressLayoutService $layout,
        private AddressAllocationService $allocation,
        private ActivityRecorder $activity,
        private PrefixListAssignmentService $assignments,
        private RouteAssignmentService $routes,
        private InterfaceAssignmentService $interfaces,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.AddressBlock::class, only: ['index']),
            new Middleware('can:create,'.AddressBlock::class, only: ['store', 'storeSubnet']),
            new Middleware('can:update,block', only: ['update']),
            new Middleware('can:delete,block', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $selected = AddressBlock::query()
            ->with(['coreSwitch', 'accessSwitch', 'parent'])
            ->firstWhere('uuid', $request->string('block')->toString());

        // Kayıt düzeyinde görme yasağı olan blok adresle de açılamaz.
        if ($selected !== null && $request->user()->cannot('view', $selected)) {
            $selected = null;
        }

        /*
         * Hangi liste açık: müşteri blokları mı, altyapı blokları mı. Seçili
         * bir blok varsa sekme ONA göre belirlenir; yoksa derin bağlantı boş
         * bir listeye düşerdi.
         */
        $infrastructure = $selected !== null
            ? $selected->purpose->isInfrastructure()
            : $request->string('tur')->toString() === 'altyapi';

        return view('addressing.index', [
            /*
             * Ağaç: kök bloklar ve her birinin İÇİ — alt ağlar ve aralarındaki
             * boşluklar. Sütunlu gezinmenin yerini aldı; gerekçesi görünümde.
             */
            'tree' => $this->blocks->tree($infrastructure, $request->user()),
            'infrastructure' => $infrastructure,
            'selected' => $selected,
            'facts' => $selected ? $this->blocks->facts($selected) : [],

            /*
             * HOST DÜZEYİ: bölünmemiş bir blokta tek tek adresler ve her
             * birini kimin tuttuğu. "Bu /29'un hangi adresi boşta" sorusunun
             * cevabı; ağ düzeyindeki ağaç bunu söylemez.
             */
            'hosts' => $selected ? $this->layout->hosts($selected) : null,
            'usage' => $selected ? $this->blocks->usage($selected) : null,
            'maskOptions' => $this->allocation->maskOptions(),
            'subnetMaskOptions' => $selected ? $this->allocation->subnetMaskOptions($selected) : [],
            'coreSwitchOptions' => $this->switchOptions($request, SwitchRole::Core),
            'accessSwitchOptions' => $this->switchOptions($request, SwitchRole::Access),
            'typeOptions' => $this->typeOptions(),
            'purposeOptions' => $this->purposeOptions(),
            'prefixListOptions' => $this->prefixListOptions($request),
            'uplinkOptions' => $this->uplinkOptions($request),
        ]);
    }

    public function store(AddressBlockRequest $request): RedirectResponse
    {
        $block = $this->write(fn (): AddressBlock => $this->blocks->create($request->blockAttributes()));

        $this->activity->created($block, "Adres bloğu eklendi: {$block->cidr}");

        return $this->back($block, "{$block->cidr} eklendi.".$this->announcement($block));
    }

    public function storeSubnet(AddressBlockRequest $request, AddressBlock $block): RedirectResponse
    {
        $subnet = $this->write(fn (): AddressBlock => $this->blocks->create($request->blockAttributes(), $block));

        $this->activity->created($subnet, "Alt ağ eklendi: {$subnet->cidr}");

        return $this->back($subnet, "{$subnet->cidr} eklendi.".$this->announcement($subnet));
    }

    public function update(AddressBlockRequest $request, AddressBlock $block): RedirectResponse
    {
        // Atama değişmişse eski satır cihazdan çıkarılır; öncesi burada tutulur.
        $previous = $block->prefixList;
        $previousRoute = $this->routes->fingerprint($block);
        $previousInterface = $this->interfaces->fingerprint($block);

        $this->write(function () use ($block, $request): AddressBlock {
            $this->blocks->update($block, $request->blockAttributes());

            return $block->refresh();
        }, $previous, $previousRoute, $previousInterface);

        $this->activity->updated($block, "Adres bloğu güncellendi: {$block->cidr}");

        return $this->back($block, "{$block->cidr} güncellendi.".$this->announcement($block));
    }

    public function destroy(AddressBlock $block): RedirectResponse
    {
        $cidr = $block->cidr;
        $parent = $block->parent;

        /*
         * Blok cihazdaki prefix list'ten de çıkarılır; yoksa envanterde
         * karşılığı olmayan bir prefix anons edilmeye devam ederdi.
         */
        DB::transaction(function () use ($block): void {
            $this->assignments->detach($block);
            $this->routes->detach($block);
            $this->interfaces->detach($block);
            $this->blocks->delete($block);
        });

        $this->activity->deleted($block, "Adres bloğu silindi: {$cidr}");

        return $this->back($parent, "{$cidr} silindi.");
    }

    /**
     * Envanter ve cihaz TEK işlemde değişir.
     *
     * Cihaza yazma başarısız olursa envanter değişikliği de geri alınır:
     * panelin "anons ediliyor" dediği ama cihazda olmayan bir blok, bu
     * projede kabul edilen en zararlı tutarsızlıktır.
     *
     * @param  callable(): AddressBlock  $save
     */
    private function write(
        callable $save,
        ?SwitchPrefixList $previous = null,
        array $previousRoute = ['switch_id' => null, 'next_hop' => null],
        array $previousInterface = ['switch_id' => null, 'interface' => null, 'cidr' => null],
    ): AddressBlock {
        return DB::transaction(function () use ($save, $previous, $previousRoute, $previousInterface): AddressBlock {
            $block = $save();

            $this->assignments->sync($block, $previous);

            /*
             * Atamanın her türü cihaza yazılır. Yalnızca panelde
             * kaydedilselerdi blok "bağlanmış" görünürken cihazda karşılığı
             * olmazdı: prefix list anons etmez, route yönlendirmez, arayüz
             * geçit olmazdı.
             */
            $this->routes->sync($block, $previousRoute);
            $this->interfaces->sync($block, $previousInterface);

            return $block;
        });
    }

    /**
     * Kullanıcıya cihaza ne yazıldığı söylenir; sessizce commit atılmaz.
     */
    private function announcement(AddressBlock $block): string
    {
        $yapilanlar = [];

        if ($block->switch_prefix_list_id !== null) {
            $yapilanlar[] = 'Prefix list cihaza yazıldı.';
        }

        if ($block->connection_type === AddressConnectionType::NextHop && $block->next_hop !== null) {
            $yapilanlar[] = 'Statik route cihaza yazıldı.';
        }

        if (in_array($block->connection_type, [AddressConnectionType::Vlan, AddressConnectionType::Port], true)) {
            $yapilanlar[] = 'Geçit adresi cihaza yazıldı.';
        }

        return $yapilanlar === [] ? '' : ' '.implode(' ', $yapilanlar);
    }

    private function back(?AddressBlock $block, string $status): RedirectResponse
    {
        return redirect()
            ->route('addressing.index', ['block' => $block?->uuid])
            ->with('status', $status);
    }

    /**
     * Omurga ve erişim seçimleri aynı listeyi kullanır; hangi switchin hangi
     * rolde olduğu etikette görünür, seçim rolle sınırlanmaz (bir blok dağıtım
     * switchinde de durabilir).
     *
     * @return array<int|string, string>
     */
    /**
     * Statik route hedefi olarak seçilebilecek uplink adresleri.
     *
     * Envanterdeki switchlerin uplink IP'leri listelenir; müşteri switchleri
     * envanterde olmadığı için formda ayrıca elle giriş de vardır.
     *
     * @return array<string, string>
     */
    private function uplinkOptions(Request $request): array
    {
        return ['' => 'Seçilmedi'] + NetworkSwitch::query()
            ->visibleTo($request->user())
            ->whereNotNull('uplink_ip')
            ->orderBy('hostname')
            ->get()
            ->mapWithKeys(fn (NetworkSwitch $switch): array => [
                $switch->uplink_ip => "{$switch->hostname} · {$switch->uplink_ip}",
            ])
            ->all();
    }

    /**
     * Omurga switchlerdeki prefix list'ler, switch adına göre gruplanır.
     * Liste cihazdan eşitlenir; elle yazılmaz.
     *
     * @return array<string, array<string, string>|string>
     */
    private function prefixListOptions(Request $request): array
    {
        $gruplar = SwitchPrefixList::query()
            ->with('switch')
            ->whereHas('switch', fn ($query) => $query
                ->visibleTo($request->user())
                ->where('role', SwitchRole::Core->value))
            ->orderBy('name')
            ->get()
            ->groupBy(fn (SwitchPrefixList $list): string => $list->switch->hostname)
            ->map(fn ($listeler) => $listeler->mapWithKeys(
                fn (SwitchPrefixList $list): array => [$list->uuid => $list->name],
            )->all())
            ->all();

        return ['' => 'Seçilmedi'] + $gruplar;
    }

    /**
     * Omurga ve erişim seçicileri aynı listeyi göstermez: alanın adı rolü
     * söylüyorsa listede başka roldeki switch bulunmamalı.
     *
     * @return array<string, string>
     */
    private function switchOptions(Request $request, SwitchRole $role): array
    {
        return ['' => 'Seçilmedi'] + NetworkSwitch::query()
            ->visibleTo($request->user())
            ->where('role', $role->value)
            ->orderBy('role')
            ->orderBy('hostname')
            ->get()
            ->mapWithKeys(fn (NetworkSwitch $switch): array => [
                $switch->uuid => "{$switch->hostname} · {$switch->role->label()}",
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    /**
     * Bloğun ne için ayrıldığı. Uplink ve peering blokları private'dır ve
     * müşteri bloklarıyla aynı havuzda aranmamalı.
     *
     * @return array<string, string>
     */
    private function purposeOptions(): array
    {
        return collect(AddressPurpose::cases())
            ->mapWithKeys(fn (AddressPurpose $case): array => [$case->value => $case->label()])
            ->all();
    }

    private function typeOptions(): array
    {
        return ['' => 'Route edilmedi'] + collect(AddressConnectionType::cases())
            ->mapWithKeys(fn (AddressConnectionType $type): array => [$type->value => $type->label()])
            ->all();
    }
}
