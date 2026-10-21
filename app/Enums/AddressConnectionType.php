<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bloğun nereye route edildiği.
 *
 * Bloğun hangi yöntemle bağlandığı.
 *
 * VLAN ve Port bir erişim switchine bağlanır. NextHop statik route'tur: blok
 * bir sonraki atlama IP'sine yönlendirilir. O IP ya envanterdeki bir switchin
 * uplink adresidir ya da elle girilir — müşteri switchleri envanterde
 * olmadığı için elle giriş şart.
 * PrefixList ise OMURGA switchteki bir policy-options prefix list'ine girer
 * ("set policy-options prefix-list musteri1-PREFIXES 192.0.2.0/24") ve
 * erişim switchiyle ilgisi yoktur.
 *
 * Erişim switchi yalnızca VLAN ve Port için zorunludur.
 */
enum AddressConnectionType: string
{
    case Vlan = 'vlan';
    case Port = 'port';
    case NextHop = 'next_hop';
    case PrefixList = 'prefix_list';

    public function label(): string
    {
        return match ($this) {
            self::Vlan => 'VLAN',
            self::Port => 'Port',
            self::NextHop => 'Static Route',
            self::PrefixList => 'Prefix list',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Vlan => BadgeVariant::Purple,
            self::Port => BadgeVariant::Blue,
            self::NextHop => BadgeVariant::Cyan,
            self::PrefixList => BadgeVariant::Orange,
        };
    }
}
