<?php

declare(strict_types=1);

namespace App\Services\Switching;

use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchIpAddress;
use App\Services\Addressing\AddressCandidateService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bir switche elle tanımlanabilecek IP adresleri.
 *
 * Adres serbest yazılmaz: yalnızca Adresleme'de bu switche atanmış, daha
 * alt bloklara bölünmemiş blokların kullanılabilir adresleri seçilebilir.
 * Böylece envanterde blokta karşılığı olmayan adres oluşmaz. Maske de
 * bloktan gelir, ayrıca sorulmaz.
 *
 * Adresin kendisini üretme işi AddressCandidateService'tedir; burada yalnızca
 * "bu switche atanmış bloklar" daraltması vardır.
 */
class SwitchAddressCandidateService
{
    public function __construct(private AddressCandidateService $candidates) {}

    /**
     * Açılır liste için blok başlığı => (adres => adres) eşlemesi.
     *
     * @return array<string, array<string, string>>
     */
    public function groupedOptions(NetworkSwitch $switch): array
    {
        return $this->candidates->group($this->blocks($switch));
    }

    /**
     * Var olan bir kaydı düzenlerken listeye onun KENDİ adresini de ekler.
     *
     * Adres cihazdan okunmuştur: Adresleme'de karşılığı olan bir blok
     * bulunmasa bile cihazda duruyordur. Listeye konmazsa düzenleme
     * penceresinde adres alanı hiç çizilmez ve kullanıcı yalnızca portu
     * değiştirmek istese bile kaydı kaydedemez.
     *
     * Yeni adres eklemek yine bloğa bağlıdır; burada eklenen tek şey zaten
     * var olan adrestir.
     *
     * @param  array<string, array<string, string>>  $groups
     * @return array<string, array<string, string>>
     */
    public function withCurrent(array $groups, SwitchIpAddress $address): array
    {
        foreach ($groups as $addresses) {
            if (isset($addresses[$address->address])) {
                return $groups;
            }
        }

        return ['Cihazdaki adres' => [$address->address => $address->cidr]] + $groups;
    }

    /**
     * Adresin cihaza yazılacak tam hâli: "adres/maske".
     *
     * Maskenin TEK KAYNAĞI adresin ait olduğu bloktur; blok yoksa adres bu
     * switche tanımlanamaz ve null döner.
     *
     * İki ekran da buradan geçer — IP adresleri ekranı ve port ekranı. Aynı
     * kuralı iki yerde yazmak, birinin sessizce ayrışması demekti.
     */
    public function cidrFor(NetworkSwitch $switch, string $address): ?string
    {
        $block = $this->blockFor($switch, $address);

        return $block === null ? null : trim($address).'/'.$block->prefix_length;
    }

    /**
     * Adresin ait olduğu blok; maske buradan alınır. Blok yoksa adres bu
     * switche tanımlanamaz.
     */
    public function blockFor(NetworkSwitch $switch, string $address): ?AddressBlock
    {
        /*
         * Karşılaştırma PAKETLENMİŞ BAYT üzerinden: ip2long IPv6 adreste
         * false döndürüyor ve blok hiç bulunamıyordu, yani IPv6 adres
         * panelden verilemiyordu.
         */
        $binary = @inet_pton(trim($address));

        if ($binary === false) {
            return null;
        }

        return $this->blocks($switch)->first(
            fn (AddressBlock $block): bool => strlen((string) $block->range_start) === strlen($binary)
                && $binary >= $block->range_start
                && $binary <= $block->range_end,
        );
    }

    /**
     * Switche atanmış, bölünmemiş bloklar.
     *
     * @return Collection<int, AddressBlock>
     */
    private function blocks(NetworkSwitch $switch): Collection
    {
        return AddressBlock::query()
            ->where('is_subnetted', false)
            ->where(fn ($query) => $query
                ->where('core_switch_id', $switch->id)
                ->orWhere('access_switch_id', $switch->id))
            ->orderBy('range_start')
            ->get();
    }
}
