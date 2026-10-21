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
        $altAglar = $block->subnets()
            // Görme yasağı olan alt ağ listelenmez (yeri boşluk olarak görünür).
            ->when($user !== null, fn ($query) => $query->visibleTo($user))
            ->with(['accessSwitch', 'coreSwitch', 'vlan'])
            ->orderBy('range_start')
            ->get();

        $satirlar = [];
        $imlec = $block->range_start;
        $son = $block->range_end;

        foreach ($altAglar as $alt) {
            /* Alt ağdan ÖNCE kalan yer boşluktur. */
            if ($imlec < $alt->range_start) {
                $satirlar[] = $this->bosluk($imlec, Cidr::decrement($alt->range_start), $block);
            }

            $satirlar[] = [
                'tur' => 'alt-ag',
                'cidr' => $alt->cidr,
                'blok' => $alt,
                'adres' => $alt->toCidr()->usableCount(),
            ];

            $sonraki = Cidr::increment($alt->range_end);

            /* Taşma: blok adres uzayının sonunda bitiyor. */
            if ($sonraki <= $alt->range_end) {
                return $satirlar;
            }

            $imlec = $sonraki;
        }

        /* Sondan artan yer. */
        if ($imlec <= $son) {
            $satirlar[] = $this->bosluk($imlec, $son, $block);
        }

        return $satirlar;
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
        $toplam = $cidr->usableCount();

        if ($block->is_subnetted || $toplam === null || $toplam > self::HOST_LIMIT) {
            return ['sinirli' => true, 'toplam' => $toplam, 'dolu' => 0, 'adresler' => []];
        }

        $sahipler = $this->owners($block);
        $adresler = [];

        $adres = inet_pton($cidr->firstUsable());
        $bitis = inet_pton($cidr->lastUsable());

        while ($adres <= $bitis && count($adresler) < self::HOST_LIMIT) {
            $metin = (string) inet_ntop($adres);
            $sahip = $sahipler[$metin] ?? null;

            $adresler[] = [
                'adres' => $metin,
                'sahip' => $sahip['ad'] ?? null,
                'tur' => $sahip['tur'] ?? null,
            ];

            $sonraki = Cidr::increment($adres);

            if ($sonraki <= $adres) {
                break;
            }

            $adres = $sonraki;
        }

        return [
            'sinirli' => false,
            'toplam' => $toplam,
            'dolu' => count(array_filter($adresler, static fn (array $a): bool => $a['sahip'] !== null)),
            'adresler' => $adresler,
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
        $bas = $block->range_start;
        $son = $block->range_end;

        $icinde = function (?string $adres) use ($bas, $son): bool {
            if ($adres === null || $adres === '') {
                return false;
            }

            $paket = @inet_pton($adres);

            /* Alan adı da yazılabiliyor (sunucu kaydı); adres değilse atla. */
            return $paket !== false && strlen($paket) === strlen($bas) && $paket >= $bas && $paket <= $son;
        };

        $sahipler = [];

        foreach (SwitchIpAddress::query()->with('switch')->get() as $ip) {
            if ($icinde($ip->address)) {
                $sahipler[(string) $ip->address] = [
                    'ad' => trim(($ip->switch?->hostname ?? '?').' · '.($ip->interface_name ?? '')),
                    'tur' => 'switch',
                ];
            }
        }

        foreach (NetworkSwitch::query()->get(['hostname', 'management_ip', 'uplink_ip']) as $switch) {
            foreach (['management_ip' => 'yönetim', 'uplink_ip' => 'uplink'] as $alan => $etiket) {
                if ($icinde($switch->{$alan})) {
                    $sahipler[(string) $switch->{$alan}] ??= [
                        'ad' => $switch->hostname.' · '.$etiket,
                        'tur' => 'switch',
                    ];
                }
            }
        }

        foreach (Server::query()->get(['short_name', 'ip_address']) as $sunucu) {
            if ($icinde($sunucu->ip_address)) {
                $sahipler[(string) $sunucu->ip_address] ??= [
                    'ad' => (string) $sunucu->short_name,
                    'tur' => 'sunucu',
                ];
            }
        }

        foreach (BgpNeighbor::query()->get(['address']) as $komsu) {
            if ($icinde($komsu->address)) {
                $sahipler[(string) $komsu->address] ??= ['ad' => 'BGP komşusu', 'tur' => 'bgp'];
            }
        }

        return $sahipler;
    }

    /**
     * @return array{tur: 'bosluk', cidr: string, parcalar: list<string>, adres: int|null}
     */
    private function bosluk(string $bas, string $son, AddressBlock $block): array
    {
        $parcalar = Cidr::fromRange($bas, $son, $block->toCidr()->isIpv6);

        return [
            'tur' => 'bosluk',
            /* Başlık: "10.0.0.8 – 10.0.0.29" — insanın okuduğu biçim. */
            'cidr' => inet_ntop($bas).' – '.inet_ntop($son),
            'parcalar' => array_map(static fn (Cidr $c): string => $c->toString(), $parcalar),
            'adres' => $this->say($parcalar),
        ];
    }

    /**
     * Boşluktaki adres sayısı. IPv6'da sayı tamsayıya sığmayabilir; o zaman
     * null döner ve ekran sayı yerine yalnızca parçaları gösterir.
     *
     * @param  list<Cidr>  $parcalar
     */
    private function say(array $parcalar): ?int
    {
        $toplam = 0;

        foreach ($parcalar as $parca) {
            $adet = $parca->usableCount();

            if ($adet === null) {
                return null;
            }

            $toplam += $adet;
        }

        return $toplam;
    }
}
