<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Banned = 'banned';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Inactive => 'Pasif',
            self::Banned => 'Engelli',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Active => BadgeVariant::Green,
            self::Inactive => BadgeVariant::Gray,
            self::Banned => BadgeVariant::Red,
        };
    }
}
