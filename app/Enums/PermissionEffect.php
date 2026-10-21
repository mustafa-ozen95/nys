<?php

declare(strict_types=1);

namespace App\Enums;

enum PermissionEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';

    public function label(): string
    {
        return match ($this) {
            self::Allow => 'İzin Ver',
            self::Deny => 'Reddet',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Allow => BadgeVariant::Green,
            self::Deny => BadgeVariant::Red,
        };
    }
}
