<?php

declare(strict_types=1);

namespace App\Enums;

enum PortMode: string
{
    case Access = 'access';
    case Trunk = 'trunk';
    case Routed = 'routed';
    case Unconfigured = 'unconfigured';

    public function label(): string
    {
        return match ($this) {
            self::Access => 'Access',
            self::Trunk => 'Trunk',
            self::Routed => 'Routed',
            self::Unconfigured => 'Tanımsız',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Access => BadgeVariant::Blue,
            self::Trunk => BadgeVariant::Purple,
            self::Routed => BadgeVariant::Cyan,
            self::Unconfigured => BadgeVariant::Gray,
        };
    }
}
