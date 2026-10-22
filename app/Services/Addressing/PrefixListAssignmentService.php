<?php

declare(strict_types=1);

namespace App\Services\Addressing;

use App\Models\AddressBlock;
use App\Models\SwitchPrefixList;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\PrefixListConfigurator;
use Illuminate\Support\Collection;

/**
 * Adres bloğunun prefix list atamasını CİHAZLA eşitler.
 *
 * Atama yalnızca envantere yazılsaydı panel "bu blok omurgadan anons
 * ediliyor" der ama cihazda o satır olmazdı. Panelin söylediği ile cihazdaki
 * gerçeğin ayrılması, bu projede kabul edilen en büyük hata sınıfıdır —
 * anons edilmediği hâlde edildiği sanılan bir blok fark edilmez.
 *
 * Bu yüzden atama gerçek bir yazmadır:
 *   set policy-options prefix-list <liste> <blok>/<maske>
 *
 * Atama kaldırıldığında ya da başka bir listeye taşındığında eski satır
 * cihazdan da çıkarılır.
 *
 * Blok ayrıca ÇIKIŞ BEKÇİSİ listelerine de yazılır. Yaygın yapı şu: uplink
 * çıkışındaki filtre "kaynağı şu listede olanı kabul et, kalanı düşür" der —
 * yani listede olmayan blok anons edilse bile DIŞARI ÇIKAMAZ. Yalnızca anons
 * listesine yazmak, çalışmayan bir müşteri bağlantısı bırakırdı.
 */
class PrefixListAssignmentService
{
    public function __construct(private PrefixListConfigurator $lists) {}

    /**
     * @param  ?SwitchPrefixList  $previous  Değişiklikten ÖNCEKİ liste
     * @return list<string> Kullanıcıya söylenecek işlemler
     *
     * @throws NetconfException
     */
    public function sync(AddressBlock $block, ?SwitchPrefixList $previous): array
    {
        $current = $block->switch_prefix_list_id === null
            ? null
            : SwitchPrefixList::query()->with('switch')->find($block->switch_prefix_list_id);

        if ($previous?->id === $current?->id) {
            return [];
        }

        /*
         * Bekçi listeler HER ŞEYDEN ÖNCE belirlenir: ilk yazmadan sonra
         * cihazdan geri okuma yapılıyor ve o okuma bu bilgiyi tazeliyor.
         * Sonradan sorulsaydı, yazma sırasında değişen bir duruma göre karar
         * verilmiş olurdu.
         */
        $guards = $this->guards($previous?->switch_id ?? $current?->switch_id);

        $done = [];

        if ($previous !== null) {
            $done = [
                ...$done,
                ...$this->remove($previous, $block->cidr),
                ...$this->guard($guards, $previous, $block->cidr, add: false),
            ];
        }

        if ($current !== null) {
            $this->lists->addEntry($current->switch, $current, $block->cidr);
            $done[] = "{$block->cidr} → {$current->name} ({$current->switch->hostname})";

            $done = [...$done, ...$this->guard($guards, $current, $block->cidr, add: true)];
        }

        return $done;
    }

    /**
     * Blok silinirken atandığı satır da cihazdan çıkarılır; yoksa cihazda
     * envanterde karşılığı olmayan bir prefix kalırdı.
     *
     * @return list<string>
     *
     * @throws NetconfException
     */
    public function detach(AddressBlock $block): array
    {
        if ($block->switch_prefix_list_id === null) {
            return [];
        }

        $list = SwitchPrefixList::query()->with('switch')->find($block->switch_prefix_list_id);

        if ($list === null) {
            return [];
        }

        $guards = $this->guards($list->switch_id);

        return [...$this->remove($list, $block->cidr), ...$this->guard($guards, $list, $block->cidr, add: false)];
    }

    /**
     * Bloğu switchin ÇIKIŞ BEKÇİSİ listelerine ekler ya da onlardan çıkarır.
     *
     * Seçilen listenin kendisi bekçiyse tekrar işlem yapılmaz. Bekçi liste
     * yoksa (o switchte böyle bir filtre kurulu değilse) yapacak bir şey de
     * yoktur.
     *
     * @param  Collection<int, SwitchPrefixList>  $bekciler
     * @return list<string>
     *
     * @throws NetconfException
     */
    private function guard(Collection $guards, SwitchPrefixList $chosen, string $cidr, bool $add): array
    {
        $done = [];

        foreach ($guards as $guard) {
            // Seçilen listenin kendisi bekçiyse iki kez yazılmaz.
            if ($guard->id === $chosen->id) {
                continue;
            }

            if ($add) {
                $this->lists->addEntry($guard->switch, $guard, $cidr);
                $done[] = "{$cidr} → {$guard->name} (çıkış izni)";

                continue;
            }

            $done = [...$done, ...$this->remove($guard, $cidr)];
        }

        return $done;
    }

    /**
     * Switchin çıkış bekçisi listeleri.
     *
     * @return Collection<int, SwitchPrefixList>
     */
    private function guards(?int $switchId): Collection
    {
        if ($switchId === null) {
            return new Collection;
        }

        return SwitchPrefixList::query()
            ->where('switch_id', $switchId)
            ->whereNotNull('egress_interfaces')
            ->with('switch')
            ->get()
            ->filter(fn (SwitchPrefixList $list): bool => $list->is_egress_guard)
            ->values();
    }

    /**
     * @return list<string>
     *
     * @throws NetconfException
     */
    private function remove(SwitchPrefixList $list, string $cidr): array
    {
        [$address, $length] = explode('/', $cidr, 2);

        $entry = $list->entries()
            ->where('address', $address)
            ->where('prefix_length', (int) $length)
            ->first();

        // Cihazda zaten yoksa yapılacak bir şey yok; hata değildir.
        if ($entry === null) {
            return [];
        }

        $this->lists->removeEntry($list->switch, $list, $entry);

        return ["{$cidr} ✕ {$list->name} ({$list->switch->hostname})"];
    }
}
