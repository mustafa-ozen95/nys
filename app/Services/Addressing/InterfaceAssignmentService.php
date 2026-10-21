<?php

declare(strict_types=1);

namespace App\Services\Addressing;

use App\Enums\AddressConnectionType;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchIpAddress;
use App\Services\Netconf\IpAddressConfigurator;
use App\Services\Netconf\NetconfException;
use App\Support\Net\Cidr;

/**
 * Adres bloğunun VLAN/Port atamasını CİHAZA yazar.
 *
 * Blok bir VLAN'a ya da porta bağlandığında panelde kayıt oluşuyordu ama
 * cihazda hiçbir arayüz o bloğu taşımıyordu: blok "bağlandı" görünürken
 * kimse geçit (gateway) değildi.
 *
 * Yazılan adres bloğun İLK KULLANILABİLİR adresidir — 192.0.2.0/29 için
 * 192.0.2.1/29. Geçidin ilk adres olması yaygın kuraldır ve panelin
 * dağıttığı bloklarda da böyle uygulanır; başka bir adres gerekiyorsa IP
 * adresleri ekranından elle verilir.
 *
 * Tek adreslik bloklara (/32) ve nokta-nokta olmayan hiçbir şeye zorlama
 * yapılmaz: kullanılabilir adresi olmayan blok atlanır.
 */
class InterfaceAssignmentService
{
    public function __construct(private IpAddressConfigurator $addresses) {}

    /**
     * Bloğun mevcut arayüz atamasının parmak izi.
     *
     * @return array{switch_id: ?int, interface: ?string, cidr: ?string}
     */
    public function fingerprint(AddressBlock $block): array
    {
        $interface = $this->interfaceFor($block);

        return $interface === null
            ? ['switch_id' => null, 'interface' => null, 'cidr' => null]
            : [
                'switch_id' => $block->access_switch_id ?? $block->core_switch_id,
                'interface' => $interface,
                'cidr' => $this->gateway($block),
            ];
    }

    /**
     * @param  array{switch_id: ?int, interface: ?string, cidr: ?string}  $previous
     * @return list<string>
     *
     * @throws NetconfException
     */
    public function sync(AddressBlock $block, array $previous): array
    {
        $current = $this->fingerprint($block->refresh());

        if ($previous === $current) {
            return [];
        }

        $yapilanlar = [];

        if ($previous['switch_id'] !== null) {
            $yapilanlar = [...$yapilanlar, ...$this->remove($previous)];
        }

        if ($current['switch_id'] !== null && $current['cidr'] !== null) {
            $switch = NetworkSwitch::query()->find($current['switch_id']);

            if ($switch?->netconfCredential !== null) {
                $this->addresses->add($switch, (string) $current['interface'], (string) $current['cidr']);
                $yapilanlar[] = "{$current['cidr']} → {$current['interface']} ({$switch->hostname})";
            }
        }

        return $yapilanlar;
    }

    /**
     * @return list<string>
     *
     * @throws NetconfException
     */
    public function detach(AddressBlock $block): array
    {
        return $this->remove($this->fingerprint($block));
    }

    /**
     * Cihazın hangi arayüzüne yazılacağı.
     *
     * VLAN'da L3 arayüzü VLAN KAYDINDAN okunur, portta ise portun sıfırıncı
     * birimidir. Diğer yöntemlerin (statik route, prefix list) arayüzle işi
     * yoktur.
     */
    private function interfaceFor(AddressBlock $block): ?string
    {
        return match ($block->connection_type) {
            AddressConnectionType::Vlan => $this->vlanInterface($block),
            AddressConnectionType::Port => $block->port_name === null ? null : "{$block->port_name}.0",
            default => null,
        };
    }

    /**
     * VLAN'ın L3 arayüzü ENVANTERDEN okunur, etiketten TÜRETİLMEZ.
     *
     * Birim numarası etikete eşit DEĞİLDİR. Örneğin bir kabin switchinde:
     *
     *     vlans V10 vlan-id 10   l3-interface vlan.9
     *     vlans V11 vlan-id 11   l3-interface vlan.10
     *
     * "vlan.<etiket>" kuralı V10'a verilen geçidi vlan.10'a, yani V11'in
     * arayüzüne yazardı: adres komşu VLAN'da, yanlış müşteride belirirdi.
     * Ad da şemaya bağlıdır — ELS'te arayüz "irb"dir, "vlan" değil — ve
     * dizgeyi elle kurmak orada da yanlış arayüz üretirdi.
     *
     * L3 arayüzü tanımlı değilse yazılacak bir ad yoktur; blok o VLAN'a
     * bağlanamaz. İstek doğrulaması bunu zaten önden reddeder.
     */
    private function vlanInterface(AddressBlock $block): ?string
    {
        return $block->vlan?->l3_interface;
    }

    /**
     * Bloğun geçit adresi: ilk kullanılabilir adres, blok maskesiyle.
     */
    private function gateway(AddressBlock $block): ?string
    {
        $cidr = Cidr::tryParse($block->cidr);

        if ($cidr === null || $cidr->usableCount() < 1) {
            return null;
        }

        return $cidr->firstUsable().'/'.$block->prefix_length;
    }

    /**
     * @param  array{switch_id: ?int, interface: ?string, cidr: ?string}  $assignment
     * @return list<string>
     *
     * @throws NetconfException
     */
    private function remove(array $assignment): array
    {
        if ($assignment['switch_id'] === null || $assignment['cidr'] === null) {
            return [];
        }

        $switch = NetworkSwitch::query()->find($assignment['switch_id']);

        if ($switch?->netconfCredential === null) {
            return [];
        }

        [$adres] = explode('/', (string) $assignment['cidr']);

        $address = SwitchIpAddress::query()
            ->where('switch_id', $assignment['switch_id'])
            ->where('interface_name', $assignment['interface'])
            ->where('address', $adres)
            ->first();

        if ($address === null) {
            return [];
        }

        $this->addresses->remove($switch, $address);

        return ["{$assignment['cidr']} adresi {$switch->hostname} cihazından çıkarıldı"];
    }
}
