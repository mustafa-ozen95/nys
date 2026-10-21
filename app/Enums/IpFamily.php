<?php

declare(strict_types=1);

namespace App\Enums;

enum IpFamily: string
{
    case Inet = 'inet';
    case Inet6 = 'inet6';

    public function label(): string
    {
        return match ($this) {
            self::Inet => 'IPv4',
            self::Inet6 => 'IPv6',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Inet => BadgeVariant::Blue,
            self::Inet6 => BadgeVariant::Purple,
        };
    }
}
