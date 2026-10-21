<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * prefix-list-filter eşleşme türü.
 *
 * Junos'ta ZORUNLUDUR: türsüz yazılan filtre "syntax error, expecting
 * <flag-value> or <attribute-value>" ile reddedilir (gerçek cihazda
 * doğrulandı). Bu yüzden alan boolean değil, gerçek bir seçimdir.
 */
enum PrefixMatchType: string
{
    case Exact = 'exact';
    case Longer = 'longer';
    case OrLonger = 'orlonger';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Tam eşleşme',
            self::Longer => 'Yalnızca daha dar',
            self::OrLonger => 'Kendisi ve daha dar',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Exact => BadgeVariant::Blue,
            self::Longer => BadgeVariant::Purple,
            self::OrLonger => BadgeVariant::Green,
        };
    }
}
