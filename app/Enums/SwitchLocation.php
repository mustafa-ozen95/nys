<?php

declare(strict_types=1);

namespace App\Enums;

enum SwitchLocation: string
{
    case Self = 'self';
    case Plus = 'plus';

    public function label(): string
    {
        return match ($this) {
            self::Self => 'Self',
            self::Plus => 'Plus',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Self => BadgeVariant::Blue,
            self::Plus => BadgeVariant::Cyan,
        };
    }
}
