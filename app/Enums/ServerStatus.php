<?php

declare(strict_types=1);

namespace App\Enums;

enum ServerStatus: string
{
    case Online = 'online';
    case Warning = 'warning';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Çevrimiçi',
            self::Warning => 'Uyarı',
            self::Offline => 'Çevrimdışı',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Online => BadgeVariant::Green,
            self::Warning => BadgeVariant::Yellow,
            self::Offline => BadgeVariant::Red,
        };
    }
}
