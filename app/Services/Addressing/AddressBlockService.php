<?php

declare(strict_types=1);

namespace App\Services\Addressing;

use App\Enums\AddressPurpose;
use App\Enums\IpFamily;
use App\Models\AddressBlock;
use App\Models\User;
use App\Support\Net\Cidr;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * IP blok ağacının okunması ve yazılması.
 *
 * Ağ adresi, prefix ve sayısal aralık tek yerden üretilir; çakışma kontrolü
 * uygulamada değil veritabanında yapılır.
 */
class AddressBlockService
{
    public function __construct(private AddressLayoutService $layout) {}

    /**
     * Seçili bloğa giden sütun zinciri: kökler, sonra her atanın alt ağları.
     *
     * Blok ağacı istenildiği kadar derin olabilir (/24 → /27 → /29); ekran da
     * o derinliği takip eder.
     *
     * @return array<int, array{blocks: Collection<int, AddressBlock>, selected: ?string}>
     */
    public function columns(?AddressBlock $selected, bool $infrastructure = false): array
    {
        $path = $this->path($selected);
        $columns = [[
            'blocks' => $this->roots($infrastructure),
            'selected' => $path[0]->uuid ?? null,
        ]];

        foreach ($path as $index => $block) {
            $subnets = $this->subnets($block);

            if ($subnets->isEmpty()) {
                break;
            }

            $columns[] = [
                'blocks' => $subnets,
                'selected' => $path[$index + 1]->uuid ?? null,
            ];
        }

        return $columns;
    }

    /**
     * Adres ağacı: kök bloklar ve her birinin İÇİ.
     *
     * Sütunlu gezinmenin yerini aldı. Orada bir /24'ün neresinin boş olduğunu
     * görmek için bloğa tıklayıp yan sütuna geçmek ve BOŞLUKLARI kafadan
     * hesaplamak gerekiyordu — liste yalnızca tanımlı alt ağları gösteriyordu.
     * Şimdi satır yerinde açılıyor ve boşluklar da yazıyor.
     *
     * TOPLAM SATIR SINIRLI: derin bölünmüş bir blokta ağacın tamamı tek
     * sayfaya basılırsa ekran da tarayıcı da kilitlenir. Sınıra gelince o dal
     * kapalı kalır; kullanıcı bloğa tıklayıp kendi ağacını açar.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(bool $infrastructure = false, ?User $user = null): array
    {
        $remaining = self::TREE_LIMIT;

        return $this->roots($infrastructure, $user)
            ->map(fn (AddressBlock $block): array => $this->node($block, 0, $remaining, $user))
            ->all();
    }

    /** Ağaçta tek seferde basılacak en fazla satır. */
    private const TREE_LIMIT = 400;

    /** Ağacın en fazla derinliği; ötesi ekranda okunmuyor. */
    private const TREE_DEPTH = 4;

    /**
     * @return array<string, mixed>
     */
    private function node(AddressBlock $block, int $depth, int &$remaining, ?User $user = null): array
    {
        $node = [
            'block' => $block,
            'derinlik' => $depth,
            'satirlar' => [],
            'kesildi' => false,
        ];

        if ($depth >= self::TREE_DEPTH) {
            return $node;
        }

        $rows = $this->layout->network($block, $user);

        /*
         * AÇILABİLİRLİK ALT AĞIN VARLIĞINDAN gelir, is_subnetted bayrağından
         * değil. Bayrak bloğun "havuz" olduğunu söyleyen bir NİYET; alt ağ
         * doğrudan da eklenmiş olabiliyor ve o zaman bayrağa bakan ağaç
         * çocukları hiç göstermiyordu. Yaprak blokta tek satır çıkar (kendi
         * boşluğu) ve onu açmanın anlamı yok.
         */
        $hasSub = collect($rows)->contains(fn (array $row): bool => $row['tur'] === 'alt-ag');

        if (! $hasSub) {
            return $node;
        }

        foreach ($rows as $row) {
            if ($remaining-- <= 0) {
                $node['kesildi'] = true;

                break;
            }

            /*
             * Alt ağın KENDİ alt ağları varsa o da açılabilir bir dal olur;
             * yoksa düz satır. Karar yine bayrakla değil, gerçekten çocuğu
             * olup olmadığıyla verilir.
             */
            if ($row['tur'] === 'alt-ag') {
                $alt = $this->node($row['blok'], $depth + 1, $remaining, $user);

                if ($alt['satirlar'] !== []) {
                    $row['alt'] = $alt;
                }
            }

            $node['satirlar'][] = $row;
        }

        return $node;
    }

    /**
     * Kökten seçili bloğa uzanan zincir.
     *
     * @return array<int, AddressBlock>
     */
    private function path(?AddressBlock $selected): array
    {
        $path = [];

        for ($block = $selected; $block !== null; $block = $block->parent) {
            array_unshift($path, $block);
        }

        return $path;
    }

    /**
     * Kök bloklar.
     *
     * Müşteri (public) blokları ile altyapının kendi private blokları AYRI
     * listelenir: uplink bacakları ve peering /30'ları günlük işte müşteri
     * bloklarının arasında aranmaz ve aynı listede durmaları ikisini de
     * okunmaz hâle getiriyordu.
     *
     * @return Collection<int, AddressBlock>
     */
    public function roots(bool $infrastructure = false, ?User $user = null): Collection
    {
        $infrastructurePurposes = array_map(
            static fn (AddressPurpose $purpose): string => $purpose->value,
            array_filter(AddressPurpose::cases(), static fn (AddressPurpose $purpose): bool => $purpose->isInfrastructure()),
        );

        return AddressBlock::query()
            ->roots()
            // Kayıt düzeyindeki "görme" yasağı ağaçta da geçerli olmalı.
            ->when($user !== null, fn ($query) => $query->visibleTo($user))
            ->when(
                $infrastructure,
                fn ($query) => $query->whereIn('purpose', $infrastructurePurposes),
                fn ($query) => $query->whereNotIn('purpose', $infrastructurePurposes),
            )
            ->with(['coreSwitch', 'accessSwitch'])
            ->withCount(['subnets', 'subnets as assigned_subnets_count' => fn ($query) => $query->assigned()])
            ->orderBy('range_start')
            ->orderBy('network')
            ->get();
    }

    /**
     * @return Collection<int, AddressBlock>
     */
    public function subnets(AddressBlock $parent): Collection
    {
        return $parent->subnets()
            ->with(['coreSwitch', 'accessSwitch'])
            ->withCount(['subnets', 'subnets as assigned_subnets_count' => fn ($query) => $query->assigned()])
            ->orderBy('range_start')
            ->orderBy('network')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?AddressBlock $parent = null): AddressBlock
    {
        $cidr = Cidr::parse($attributes['cidr']);

        $block = AddressBlock::create($this->cidrColumns($cidr) + [
            'parent_id' => $parent?->id,
            /*
             * Alt blok üst bloğun amacını devralır: bir peering havuzundan
             * kesilen /30 da peering içindir. Kullanıcıya her seferinde
             * sorulsaydı, havuzun altında yanlış amaçlı bloklar birikirdi.
             */
            'purpose' => $attributes['purpose'] ?? $parent?->purpose ?? AddressPurpose::Customer,
            'description' => $attributes['description'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'core_switch_id' => $attributes['core_switch_id'] ?? null,
            'access_switch_id' => $attributes['access_switch_id'] ?? null,
            'connection_type' => $attributes['connection_type'] ?? null,
            'vlan_id' => $attributes['vlan_id'] ?? null,
            'port_name' => $attributes['port_name'] ?? null,
            'next_hop' => $attributes['next_hop'] ?? null,
            'switch_prefix_list_id' => $attributes['switch_prefix_list_id'] ?? null,
            'is_announced' => $attributes['is_announced'] ?? false,
        ]);

        $parent?->update(['is_subnetted' => true]);

        return $block;
    }

    /**
     * Yalnızca açıklama ve atama değişir; ağ adresi bir kez konur.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(AddressBlock $block, array $attributes): AddressBlock
    {
        $block->update(Arr::only($attributes, [
            'purpose', 'description', 'notes', 'core_switch_id', 'access_switch_id',
            'connection_type', 'vlan_id', 'port_name', 'next_hop',
            'switch_prefix_list_id', 'is_announced',
        ]));

        return $block;
    }

    /**
     * Blok ve altındaki her şey silinir; alt ağ kalmayan üst blok artık
     * bölünmüş sayılmaz.
     */
    public function delete(AddressBlock $block): void
    {
        $parent = $block->parent;

        $block->delete();

        if ($parent !== null && $parent->subnets()->doesntExist()) {
            $parent->update(['is_subnetted' => false]);
        }
    }

    /**
     * Bir bloğun alt ağlara ne kadarının ayrıldığı ve kaçının kullanımda
     * olduğu. Adres sayıları /31 ve /32 dahil doğru çalışsın diye blok
     * büyüklüğünden hesaplanır.
     *
     * @return array<string, int>
     */
    public function usage(AddressBlock $block): array
    {
        $subnets = $block->subnets()->get(['network', 'prefix_length', 'family', 'connection_type']);

        /*
         * Doluluk PREFİX UZUNLUĞUNDAN hesaplanır, adres sayısından değil:
         * bir IPv6 /48'in içinde 2^80 adres var ve hiçbir tamsayıya sığmaz.
         * Bir /52, /48'in 1/16'sıdır — oran bit farkından çıkar.
         */
        $totalBit = $block->toCidr()->maxPrefixLength() - $block->prefix_length;
        $pay = 0.0;

        foreach ($subnets as $subnet) {
            $diff = $subnet->prefix_length - $block->prefix_length;

            // 2^-fark: alt bloğun üst bloğa oranı.
            $pay += $diff >= 0 && $diff <= 62 ? 1 / (1 << $diff) : 0.0;
        }

        $countable = $block->toCidr()->usableCount();

        return [
            'total' => $countable,
            'allocated' => $countable === null ? null : (int) round($pay * $countable),
            'percent' => $totalBit > 0 ? (int) round(min($pay, 1) * 100) : 0,
            'subnets' => $subnets->count(),
            'assigned' => $subnets->whereNotNull('connection_type')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cidrColumns(Cidr $cidr): array
    {
        return [
            'network' => $cidr->network,
            'prefix_length' => $cidr->prefixLength,
            'range_start' => $cidr->addressStart(),
            'range_end' => $cidr->addressEnd(),
            'family' => $cidr->isIpv6 ? IpFamily::Inet6 : IpFamily::Inet,
        ];
    }

    /**
     * Detay panelinin hazır verisi.
     *
     * @return array<string, string|int|null>
     */
    public function facts(AddressBlock $block): array
    {
        $cidr = $block->toCidr();

        $number = $cidr->usableCount();

        /*
         * IPv6'da yayın adresi ve noktalı maske YOKTUR; onları göstermek
         * yanlış olurdu. Adres sayısı da çoğu blokta sayılamayacak kadar
         * büyüktür (bir /64'te 2^64) — "sayılamayacak kadar çok" demek,
         * sıfır ya da uydurma bir sayı yazmaktan doğrudur.
         */
        if ($cidr->isIpv6) {
            return [
                'Ağ adresi' => $cidr->network,
                'Prefix' => '/'.$cidr->prefixLength,
                'Aile' => 'IPv6',
                'Son adres' => $cidr->lastAddress(),
                'İlk kullanılabilir' => $cidr->firstUsable(),
                'Son kullanılabilir' => $cidr->lastUsable(),
                'Kullanılabilir adres' => $number === null
                    ? 'sayılamayacak kadar çok'
                    : number_format($number, 0, ',', '.'),
            ];
        }

        return [
            'Ağ adresi' => $cidr->network,
            'Yayın adresi' => $cidr->lastAddress(),
            'Ağ maskesi' => $cidr->netmask(),
            'İlk kullanılabilir' => $cidr->firstUsable(),
            'Son kullanılabilir' => $cidr->lastUsable(),
            'Kullanılabilir adres' => number_format((int) $number, 0, ',', '.'),
        ];
    }
}
