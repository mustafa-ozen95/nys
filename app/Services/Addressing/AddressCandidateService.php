<?php

declare(strict_types=1);

namespace App\Services\Addressing;

use App\Enums\AddressPurpose;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Support\Net\Cidr;
use Illuminate\Database\Eloquent\Collection;

/**
 * Panelde bir IP adresi ya da ağ seçilecek her yerin ortak kaynağı.
 *
 * Adres elle yazılmaz. Elle yazılırsa envanterde karşılığı olmayan adres
 * oluşur, aynı adres iki yere verilir ve bir bloğun gerçekte kullanılıp
 * kullanılmadığı Adresleme ekranından okunamaz. Kaynak tektir:
 * Adresleme'de tanımlı, amacı belli (müşteri / uplink / peering / yönetim)
 * bloklar.
 *
 * Elle giriş yalnızca BELGELENMİŞ özel durumlarda açılır — sağlayıcının
 * kendi adresi, envanterde olmayan müşteri switchi gibi. Bunu açan ekran
 * gerekçesini yorumda yazar.
 */
class AddressCandidateService
{
    /**
     * İstek boyunca okunan bloklar.
     *
     * Bir ekranda birden fazla adres alanı olabiliyor (yönetim IP'si ve
     * uplink IP'si gibi) ve her biri aynı tabloyu okurdu. Servis istek
     * başına çözüldüğü için tek okuma hepsine yeter.
     */
    private ?Collection $loaded = null;

    /**
     * Tek bir blokta listelenecek en fazla adres. /16 gibi büyük bloklarda
     * açılır listeyi kullanılmaz hâle getirmemek için sınırlanır.
     */
    public const BLOCK_LIMIT = 512;

    /**
     * Amaca göre host adresleri: blok başlığı => (adres => adres).
     *
     * @param  list<AddressPurpose>  $purposes
     * @return array<string, array<string, string>>
     */
    public function hostOptions(array $purposes = []): array
    {
        return $this->group($this->blocks($purposes));
    }

    /**
     * Verilen blokların kullanılabilir adreslerini gruplar.
     *
     * @param  iterable<AddressBlock>  $blocks
     * @return array<string, array<string, string>>
     */
    public function group(iterable $blocks): array
    {
        $groups = [];

        foreach ($blocks as $block) {
            $addresses = $this->usable($block);

            if ($addresses !== []) {
                $groups[$this->label($block)] = array_combine($addresses, $addresses);
            }
        }

        return $groups;
    }

    /**
     * Amaca göre BLOKLAR: cidr => etiket. Prefix list girişi gibi tek tek
     * adres değil ağ seçilen yerler için.
     *
     * @param  list<AddressPurpose>  $purposes
     * @return array<string, string>
     */
    public function blockOptions(array $purposes = [], ?NetworkSwitch $switch = null): array
    {
        return $this->assignableBlocks($purposes, $switch)
            ->mapWithKeys(fn (AddressBlock $block): array => [$block->cidr => $this->label($block)])
            ->all();
    }

    /**
     * ATANABİLİR bloklar: bir yere bağlanmış olan listelenmez.
     *
     * Bir blok bir switchin VLAN'ına, portuna ya da route'una bağlandığı anda
     * tükenir; ikinci bir yere verilmesi çakışma demektir. Sahibi olan
     * switchin kendi ekranında görünmeye devam eder, yoksa orada düzenlenemez
     * hâle gelirdi.
     *
     * Listeden çıkarmak tek başına yeterli değil — alanlar elle giriş de
     * kabul ediyor; asıl kural doğrulamada (bkz. SwitchRouteRequest).
     *
     * @param  list<AddressPurpose>  $purposes
     * @return Collection<int, AddressBlock>
     */
    private function assignableBlocks(array $purposes, ?NetworkSwitch $switch): Collection
    {
        $bloklar = AddressBlock::query()
            ->assignable($switch)
            ->orderBy('range_start')
            ->get();

        if ($purposes === []) {
            return $bloklar;
        }

        return $bloklar
            ->filter(fn (AddressBlock $block): bool => in_array($block->purpose, $purposes, true))
            ->values();
    }

    /**
     * Bloktaki kullanılabilir adresler. /31 ve /32'de ağ ve yayın adresi
     * kavramı yoktur, iki uç da kullanılır.
     *
     * @return array<int, string>
     */
    public function usable(AddressBlock $block): array
    {
        $cidr = $block->toCidr();

        /*
         * Üretim BAYT aritmetiğiyle yapılır: bir IPv6 bloğunun sınırları
         * tamsayıya sığmaz. Sayfa başına sınır (BLOCK_LIMIT) zaten var ve
         * IPv6'da asıl işi o yapıyor — bir /64'ün tamamı listelenemez,
         * kullanıcı adresi elle yazar.
         */
        $address = inet_pton($cidr->firstUsable());
        $end = inet_pton($cidr->lastUsable());

        $addresses = [];

        while ($address <= $end && count($addresses) < self::BLOCK_LIMIT) {
            $addresses[] = (string) inet_ntop($address);

            $sonraki = Cidr::increment($address);

            if ($sonraki <= $address) {
                break;
            }

            $address = $sonraki;
        }

        return $addresses;
    }

    public function label(AddressBlock $block): string
    {
        return implode(' · ', array_filter([
            $block->cidr,
            $block->purpose?->label(),
            $block->description,
        ]));
    }

    /**
     * Bölünmemiş bloklar: bölünmüş blok bir havuzdur, adres taşımaz ve
     * önerilirse kullanıcı yanlış seçim yapar.
     *
     * @param  list<AddressPurpose>  $purposes
     * @return Collection<int, AddressBlock>
     */
    private function blocks(array $purposes): Collection
    {
        $this->loaded ??= AddressBlock::query()
            ->where('is_subnetted', false)
            ->orderBy('range_start')
            ->get();

        if ($purposes === []) {
            return $this->loaded;
        }

        return $this->loaded
            ->filter(fn (AddressBlock $block): bool => in_array($block->purpose, $purposes, true))
            ->values();
    }
}
