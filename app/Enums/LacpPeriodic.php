<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * LACP mesaj sıklığı: fast saniyede bir, slow 30 saniyede bir.
 *
 * İki uç farklı hızda konuşabilir; hızlı olan tarafın isteği geçerlidir.
 */
enum LacpPeriodic: string
{
    case Fast = 'fast';
    case Slow = 'slow';

    public function label(): string
    {
        return match ($this) {
            self::Fast => 'Hızlı (1 sn)',
            self::Slow => 'Yavaş (30 sn)',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Fast => BadgeVariant::Blue,
            self::Slow => BadgeVariant::Gray,
        };
    }
}
