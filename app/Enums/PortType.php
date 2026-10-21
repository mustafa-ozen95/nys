<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Arayüzün türü.
 *
 * Fiziksel port donanımdan gelir ve cihazdan kaybolmaz. Toplama arayüzü (ae)
 * ise chassis tarafından YARATILIR: device-count kaç ise o kadar tanesi
 * vardır ve sayı düşürülünce üsttekiler ortadan kalkar.
 */
enum PortType: string
{
    case Physical = 'physical';
    case Aggregated = 'aggregated';

    public function label(): string
    {
        return match ($this) {
            self::Physical => 'Fiziksel',
            self::Aggregated => 'Toplama (LAG)',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Physical => BadgeVariant::Gray,
            self::Aggregated => BadgeVariant::Purple,
        };
    }
}
