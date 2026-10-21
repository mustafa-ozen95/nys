<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Anons metninin hangi dilde üretileceği. Alıcı başına belirlenir.
 */
enum MailLocale: string
{
    case Tr = 'tr';
    case En = 'en';

    public function label(): string
    {
        return match ($this) {
            self::Tr => 'Türkçe',
            self::En => 'İngilizce',
        };
    }

    public function short(): string
    {
        return strtoupper($this->value);
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Tr => BadgeVariant::Gray,
            self::En => BadgeVariant::Blue,
        };
    }
}
