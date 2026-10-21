<?php

declare(strict_types=1);

namespace App\Enums;

enum ConnectionProtocol: string
{
    case Ssh = 'ssh';
    case Rdp = 'rdp';

    public function label(): string
    {
        return match ($this) {
            self::Ssh => 'SSH',
            self::Rdp => 'RDP',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Ssh => BadgeVariant::Green,
            self::Rdp => BadgeVariant::Blue,
        };
    }
}
