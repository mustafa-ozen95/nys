<?php

declare(strict_types=1);

namespace App\Enums;

enum PortStatus: string
{
    case Up = 'up';
    case Down = 'down';
    case Testing = 'testing';
    case Dormant = 'dormant';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Up => 'Açık',
            self::Down => 'Kapalı',
            self::Testing => 'Test',
            self::Dormant => 'Uyku',
            self::Unknown => 'Bilinmiyor',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Up => BadgeVariant::Green,
            self::Down => BadgeVariant::Red,
            self::Testing => BadgeVariant::Yellow,
            self::Dormant => BadgeVariant::Gray,
            self::Unknown => BadgeVariant::Gray,
        };
    }
}
