<?php

declare(strict_types=1);

namespace App\Enums;

enum RouteProtocol: string
{
    case Static = 'static';
    case Direct = 'direct';
    case Local = 'local';
    case Ospf = 'ospf';
    case Bgp = 'bgp';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Static => 'Statik',
            self::Direct => 'Doğrudan',
            self::Local => 'Yerel',
            self::Ospf => 'OSPF',
            self::Bgp => 'BGP',
            self::Other => 'Diğer',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Static => BadgeVariant::Blue,
            self::Direct => BadgeVariant::Cyan,
            self::Local => BadgeVariant::Gray,
            self::Ospf => BadgeVariant::Purple,
            self::Bgp => BadgeVariant::Orange,
            self::Other => BadgeVariant::Gray,
        };
    }
}
