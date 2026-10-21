<?php

declare(strict_types=1);

namespace App\Enums;

enum ServerOs: string
{
    case Linux = 'linux';
    case Windows = 'windows';

    public function label(): string
    {
        return match ($this) {
            self::Linux => 'Linux',
            self::Windows => 'Windows',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Linux => BadgeVariant::Orange,
            self::Windows => BadgeVariant::Blue,
        };
    }
}
