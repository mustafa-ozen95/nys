<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminStatus: string
{
    case Up = 'up';
    case Down = 'down';

    public function label(): string
    {
        return match ($this) {
            self::Up => 'Etkin',
            self::Down => 'Devre Dışı',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Up => BadgeVariant::Green,
            self::Down => BadgeVariant::Gray,
        };
    }
}
