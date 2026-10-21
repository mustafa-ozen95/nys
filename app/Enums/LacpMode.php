<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * LACP rolü.
 *
 * Junos'ta bu ayar hiç yazılmayabilir; o zaman LAG statiktir ve protokol
 * konuşulmaz. Panelde "yok" seçeneği bu duruma karşılık gelir ve alan boş
 * bırakılır.
 */
enum LacpMode: string
{
    case Active = 'active';
    case Passive = 'passive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif (biz başlatırız)',
            self::Passive => 'Pasif (karşı taraf başlatır)',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Active => BadgeVariant::Green,
            self::Passive => BadgeVariant::Yellow,
        };
    }
}
