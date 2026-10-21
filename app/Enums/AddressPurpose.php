<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bir adres bloğunun ne için ayrıldığı.
 *
 * Müşteriye verilen public bloklarla, altyapının kendi kullandığı private
 * blokların (uplink bacakları, BGP peering /30'ları) aynı listede ayrımsız
 * durması karışıklık üretiyordu: peering için /30 aranırken müşteri blokları
 * da öneriliyordu.
 */
enum AddressPurpose: string
{
    case Customer = 'customer';
    case Uplink = 'uplink';
    case Peering = 'peering';
    case Management = 'management';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Müşteri',
            self::Uplink => 'Uplink',
            self::Peering => 'BGP peering',
            self::Management => 'Yönetim',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Customer => BadgeVariant::Blue,
            self::Uplink => BadgeVariant::Orange,
            self::Peering => BadgeVariant::Purple,
            self::Management => BadgeVariant::Gray,
        };
    }

    /**
     * Altyapının kendi kullandığı, dışarıya anons edilmeyen bloklar.
     */
    public function isInfrastructure(): bool
    {
        return $this !== self::Customer;
    }
}
