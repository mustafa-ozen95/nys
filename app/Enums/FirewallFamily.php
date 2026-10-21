<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Firewall filtresinin ailesi.
 *
 * IpFamily'den AYRIDIR: EX3200/EX4200'de filtre yalnızca IP üzerinde değil,
 * anahtarlama düzleminde de kurulabilir ("family ethernet-switching") ve o
 * ailenin koşulları IP ailelerinde bulunmaz (ether-type, MAC adresleri).
 * Aynı enum'a sıkıştırılsaydı IP adresi bekleyen her yer üçüncü bir değerle
 * karşılaşırdı.
 *
 * Cihazda doğrulandı (test1, commit check): üç aile de kabul ediliyor.
 */
enum FirewallFamily: string
{
    case Inet = 'inet';
    case Inet6 = 'inet6';
    case EthernetSwitching = 'ethernet-switching';

    public function label(): string
    {
        return match ($this) {
            self::Inet => 'IPv4',
            self::Inet6 => 'IPv6',
            self::EthernetSwitching => 'Ethernet switching',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Inet => BadgeVariant::Blue,
            self::Inet6 => BadgeVariant::Purple,
            self::EthernetSwitching => BadgeVariant::Cyan,
        };
    }

    /**
     * IpFamily taşıyan eski çağrılar da kabul edilir.
     *
     * Firewall ailesi IpFamily iken yazılmış çağrı noktaları var
     * (DeviceWriteScenarios); imzayı tek taraflı değiştirmek onları sessizce
     * bozardı.
     */
    public static function coerce(self|IpFamily $family): self
    {
        return $family instanceof self ? $family : self::from($family->value);
    }
}
