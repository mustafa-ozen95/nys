<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\SwitchIpAddress;

/**
 * Bir adresin panelden değiştirilip değiştirilemeyeceği.
 *
 * Panel üç şeyi temsil edebiliyor: bir VLAN'ın L3 arayüzü, bir fiziksel port
 * ve loopback (lo0.0). Cihazdaki her adres bunlardan biri değildir:
 *
 * - me0 / vme yönetim arayüzüdür. Adresi panelden değiştirilirse panelin
 *   cihaza erişimi kesilir — kendi ayağımıza sıkmak olur.
 * - bme/em0 iç arayüzlerdir; formda karşılıkları yoktur.
 *
 * Bu adresler listede görünür ama düzenlenemez. Aksi hâlde form onları
 * temsil edemediği için kullanıcı yanlış bir bağlantı seçer ve cihaza
 * beklemediği bir satır yazılır.
 *
 * lo0 YÖNETİLEBİLİR: BGP local-address ve router-id için tipik arayüz odur
 * ve panelden adres verilemeseydi o iş CLI'da kalırdı; formda karşılığı
 * vardır. Loopback'in family'si altında genelde RE koruma filtresi durur (test1
 * üzerinde ölçüldü: lo0.0 family inet yalnızca "filter input RE_FILTER"
 * taşıyor); silme yolunun aileyi kaldırmaması bu yüzden şart — bkz.
 * IpAddressConfigurator.
 */
class ManageableInterface
{
    /**
     * Panelden asla değiştirilmeyecek arayüzler.
     *
     * @var list<string>
     */
    private const PROTECTED = ['me0', 'vme', 'bme0', 'em0'];

    /**
     * Panelin kendi başına temsil edebildiği arayüzler: bağlı bir VLAN ya da
     * port kaydı olmasa da formda karşılığı vardır.
     *
     * @var list<string>
     */
    private const STANDALONE = ['lo0'];

    public function isManageable(SwitchIpAddress $address): bool
    {
        if ($this->isProtected($address->interface_name)) {
            return false;
        }

        if (in_array($this->parentOf($address->interface_name), self::STANDALONE, true)) {
            return true;
        }

        // Formda ya bir VLAN ya da bir port seçilir; ikisi de yoksa adresin
        // panelde bir karşılığı yok demektir.
        return $address->vlan_id !== null || $address->switch_port_id !== null;
    }

    public function isProtected(string $interfaceName): bool
    {
        return in_array($this->parentOf($interfaceName), self::PROTECTED, true);
    }

    /**
     * Neden değiştirilemediği; ekranda kullanıcıya gösterilir.
     */
    public function reason(SwitchIpAddress $address): string
    {
        return $this->isProtected($address->interface_name)
            ? 'Yönetim arayüzü'
            : 'Panelde karşılığı yok';
    }

    private function parentOf(string $interfaceName): string
    {
        return (string) preg_replace('/\.\d+$/', '', $interfaceName);
    }
}
