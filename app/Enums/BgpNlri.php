<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Adres ailesinin altındaki NLRI alt elemanı.
 *
 * "any" hem unicast hem multicast'i kapsar ve omurga yapılandırmalarında
 * yaygın biçimdir; varsayılan odur.
 */
enum BgpNlri: string
{
    case Any = 'any';
    case Unicast = 'unicast';
    case Multicast = 'multicast';

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Any => BadgeVariant::Gray,
            self::Unicast => BadgeVariant::Blue,
            self::Multicast => BadgeVariant::Purple,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Any => 'Hepsi (any)',
            self::Unicast => 'Unicast',
            self::Multicast => 'Multicast',
        };
    }
}
