<?php

declare(strict_types=1);

namespace App\Enums;

enum Duplex: string
{
    case Full = 'full';
    case Half = 'half';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full',
            self::Half => 'Half',
            self::Auto => 'Auto',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Full => BadgeVariant::Green,
            self::Half => BadgeVariant::Yellow,
            self::Auto => BadgeVariant::Gray,
        };
    }
}
