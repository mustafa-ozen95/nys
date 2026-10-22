<?php

declare(strict_types=1);

namespace App\Services\Addressing;

use App\Models\AddressBlock;
use App\Models\BgpNeighbor;
use App\Models\NetworkSwitch;
use App\Models\Server;
use App\Models\SwitchIpAddress;
use App\Models\User;
use App\Support\Net\Cidr;

/**
 * Bir bloğun içinde NE KULLANILIYOR, NE BOŞTA.
 *
 * AMAÇ ÇAKIŞMAYI ÖNLEMEK. Yalnızca tanımlı alt ağları gösteren bir listede
 * aralarındaki boşluklar görünmez; "bu /24'ün neresi boş" sorusunun cevabı
 * ancak elle hesaplanarak bulunur ve aynı aralık ikinci kez verilebilir.
 *
 * İKİ DÜZEY:
 *
 *  - AĞ DÜZEYİ: bloğun altındaki alt ağlar ADRES SIRASINA göre, aralarındaki
 *    boşluklarla birlikte. Boşluk düzgün bir CIDR'a denk gelmeyebilir, o
 *    yüzden en az sayıda parçaya bölünür (Cidr::fromRange) — kullanıcı oraya
 *    hangi maskenin sığdığını görmeli.
 *
 *  - HOST DÜZEYİ: bölünmemiş bir blokta tek tek adresler ve her birini KİMİN
 *    tuttuğu. Kaynak tek bir tablo değil: bir adres switch arayüzünde, bir
 *    sunucuda ya da BGP komşusunda duruyor olabilir.
 *
 * Hesap SUNUCUDA ve sayısal aralıkla yapılır; tarayıcıda metin
 * karşılaştırması yanlış sonuç verir (bkz. AddressAllocationService'in
 * başındaki not).
 */
class AddressLayoutService
{
    /**
     * Host düzeyinde listelenecek en fazla adres.
     *
     * Bir /24 iki yüz elli altı satır demek; bundan genişini tek tek basmak
     * ekranı kullanılmaz hâle getirir ve zaten okunmaz. Geniş bloklarda
     * kullanıcı önce alt ağa iner.
     */
    public const HOST_LIMIT = 256;

    /**
     * Bloğun ağ düzeyindeki yerleşimi: alt ağlar ve boşluklar, adres sırasına
     * göre.
     *
     * @return list<array{tur: 'alt-ag'|'bosluk', cidr: string, blok?: AddressBlock, parcalar?: list<string>, adres: int|null}>
     */
    public function network(AddressBlock $block, ?User $user = null): array
    {
        $subNetworks = $block->subnets()
            // Görme yasağı olan alt ağ listelenmez (yeri boşluk olarak görünür).
            ->when($user !== null, fn ($query) => $query->visibleTo($user))
            ->with(['accessSwitch', 'coreSwitch', 'vlan'])
            ->orderBy('range_start')
            ->get();

        $rows = [];
        $cursor = $block->range_start;
        $last = $block->range_end;

        foreach ($subNetworks as $alt) {
            /* Alt ağdan ÖNCE kalan yer boşluktur. */
            if ($cursor < $alt->range_start) {
                $rows[] = $this->gap($cursor, Cidr::decrement($alt->range_start), $block);
            }

            $rows[] = [
                'tur' => 'alt-ag',
                'cidr' => $alt->cidr,
                'blok' => $alt,
                'adres' => $alt->toCidr()->usableCount(),
            ];

            $next = Cidr::increment($alt->range_end);

            /* Taşma: blok adres uzayının sonunda bitiyor. */
            if ($next <= $alt->range_end) {
                return $rows;
            }

            $cursor = $next;
        }

        /* Sondan artan yer. */
        if ($cursor <= $last) {
            $rows[] = $this->gap($cursor, $last, $block);
        }

        return $rows;
    }

    /**
     * Bloktaki tek tek adresler ve onları kimin tuttuğu.
     *
     * Bölünmüş blokta çağrılmaz: orada adresler alt ağlara aittir ve üstte
     * listelemek, aynı adresi iki yerde göstermek olurdu.
     *
     * @return array{sinirli: bool, toplam: int|null, dolu: int, adresler: list<array{adres: string, sahip: ?string, tur: ?string}>}
     */
    public function hosts(AddressBlock $block): array
    {
        $cidr = $block->toCidr();
        $total = $cidr->usableCount();

        if ($block->is_subnetted || $total === null || $total > self::HOST_LIMIT) {
            return ['sinirli' => true, 'toplam' => $total, 'dolu' => 0, 'adresler' => []];
        }

        $owners = $this->owners($block);
        $addresses = [];

        $address = inet_pton($cidr->firstUsable());
        $end = inet_pton($cidr->lastUsable());

        while ($address <= $end && count($addresses) < self::HOST_LIMIT) {
            $text = (string) inet_ntop($address);
            $owner = $owners[$text] ?? null;

            $addresses[] = [
                'adres' => $text,
                'sahip' => $owner['ad'] ?? null,
                'tur' => $owner['tur'] ?? null,
            ];

            $next = Cidr::increment($address);

            if ($next <= $address) {
                break;
            }

            $address = $next;
        }

        return [
            'sinirli' => false,
            'toplam' => $total,
            'dolu' => count(array_filter($addresses, static fn (array $a): bool => $a['sahip'] !== null)),
            'adresler' => $addresses,
        ];
    }

    /**
     * Bloğun içindeki adresleri KİM tutuyor.
     *
     * KAYNAK TEK TABLO DEĞİL: bir adres switch arayüzünde, bir sunucuda ya da
     * BGP komşusunda duruyor olabilir. Hepsini toplamayan bir liste "boşta"
     * derken yanılır ve bu ekranın varlık sebebi tam da yanılmamak.
     *
     * @return array<string, array{ad: string, tur: string}>
     */
    private function owners(AddressBlock $block): array
    {
        $head = $block->range_start;
        $last = $block->range_end;

        $within = function (?string $address) use ($head, $last): bool {
            if ($address === null || $address === '') {
                return false;
            }

            $package = @inet_pton($address);

            /* Alan adı da yazılabiliyor (sunucu kaydı); adres değilse atla. */
            return $package !== false && strlen($package) === strlen($head) && $package >= $head && $package <= $last;
        };

        $owners = [];

        foreach (SwitchIpAddress::query()->with('switch')->get() as $ip) {
            if ($within($ip->address)) {
                $owners[(string) $ip->address] = [
                    'ad' => trim(($ip->switch?->hostname ?? '?').' · '.($ip->interface_name ?? '')),
                    'tur' => 'switch',
                ];
            }
        }

        foreach (NetworkSwitch::query()->get(['hostname', 'management_ip', 'uplink_ip']) as $switch) {
            foreach (['management_ip' => 'yönetim', 'uplink_ip' => 'uplink'] as $alan => $tag) {
                if ($within($switch->{$alan})) {
                    $owners[(string) $switch->{$alan}] ??= [
                        'ad' => $switch->hostname.' · '.$tag,
                        'tur' => 'switch',
                    ];
                }
            }
        }

        foreach (Server::query()->get(['short_name', 'ip_address']) as $server) {
            if ($within($server->ip_address)) {
                $owners[(string) $server->ip_address] ??= [
                    'ad' => (string) $server->short_name,
                    'tur' => 'sunucu',
                ];
            }
        }

        foreach (BgpNeighbor::query()->get(['address']) as $neighbor) {
            if ($within($neighbor->address)) {
                $owners[(string) $neighbor->address] ??= ['ad' => 'BGP komşusu', 'tur' => 'bgp'];
            }
        }

        return $owners;
    }

    /**
     * @return array{tur: 'bosluk', cidr: string, parcalar: list<string>, adres: int|null}
     */
    private function gap(string $head, string $last, AddressBlock $block): array
    {
        $fragments = Cidr::fromRange($head, $last, $block->toCidr()->isIpv6);

        return [
            'tur' => 'bosluk',
            /* Başlık: "10.0.0.8 – 10.0.0.29" — insanın okuduğu biçim. */
            'cidr' => inet_ntop($head).' – '.inet_ntop($last),
            'parcalar' => array_map(static fn (Cidr $c): string => $c->toString(), $fragments),
            'adres' => $this->count($fragments),
        ];
    }

    /**
     * Boşluktaki adres sayısı. IPv6'da sayı tamsayıya sığmayabilir; o zaman
     * null döner ve ekran sayı yerine yalnızca parçaları gösterir.
     *
     * @param  list<Cidr>  $parcalar
     */
    private function count(array $fragments): ?int
    {
        $total = 0;

        foreach ($fragments as $fragment) {
            $count = $fragment->usableCount();

            if ($count === null) {
                return null;
            }

            $total += $count;
        }

        return $total;
    }
}
