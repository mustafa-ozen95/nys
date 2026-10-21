<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Firewall teriminin sonucu.
 *
 * discard sessizce düşürür, reject ise karşı tarafa ICMP ile haber verir.
 * İkisi arasındaki fark bir tercih değil güvenlik kararıdır: reject, portun
 * kapalı olduğunu tarayana söyler.
 */
enum FirewallAction: string
{
    case Accept = 'accept';
    case Discard = 'discard';
    case Reject = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::Accept => 'Kabul et',
            self::Discard => 'Sessizce düşür',
            self::Reject => 'Reddet (haber ver)',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Accept => BadgeVariant::Green,
            self::Discard => BadgeVariant::Red,
            self::Reject => BadgeVariant::Orange,
        };
    }
}
