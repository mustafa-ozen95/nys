<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * BGP oturum durumu (RFC 4271 sonlu durum makinesi).
 *
 * Şu an elle girilir; ileride omurga switchlerden okunacak.
 */
enum BgpState: string
{
    /**
     * Durum cihazdan okunur, elle girilmez. Eşitleme gelene kadar bütün
     * oturumlar Unknown durumundadır.
     */
    case Unknown = 'unknown';
    case Idle = 'idle';
    case Connect = 'connect';
    case Active = 'active';
    case OpenSent = 'open_sent';
    case OpenConfirm = 'open_confirm';
    case Established = 'established';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Bilinmiyor',
            self::Idle => 'Idle',
            self::Connect => 'Connect',
            self::Active => 'Active',
            self::OpenSent => 'OpenSent',
            self::OpenConfirm => 'OpenConfirm',
            self::Established => 'Established',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Established => BadgeVariant::Green,
            self::OpenSent, self::OpenConfirm, self::Connect => BadgeVariant::Yellow,
            self::Active => BadgeVariant::Orange,
            self::Unknown, self::Idle => BadgeVariant::Gray,
        };
    }

    public function isUp(): bool
    {
        return $this === self::Established;
    }
}
