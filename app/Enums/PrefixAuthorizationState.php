<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bir prefixin tek bir kaynağa göre anons yetkisi.
 *
 * "Bilinmiyor" ile "Onaysız" bilerek AYRI tutulur: biri kaydın yokluğu,
 * öteki kaydın başkasını göstermesidir. İkisi de gönderimi durdurur ama
 * operatöre söylenecek şey farklıdır — birinde kayıt açtırılır, ötekinde
 * anons hakkı sorgulanır.
 */
enum PrefixAuthorizationState: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Missing = 'missing';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Onaylı',
            self::Rejected => 'Onaysız',
            self::Missing => 'Kayıt yok',
            self::Unknown => 'Sorgulanamadı',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Approved => BadgeVariant::Green,
            self::Rejected => BadgeVariant::Red,
            self::Missing => BadgeVariant::Orange,
            self::Unknown => BadgeVariant::Gray,
        };
    }

    /**
     * Yalnızca "onaylı" gönderime izin verir.
     *
     * Sorgunun düşmesi de onay değildir; kanıtlanmamış yetki, yetki sayılmaz.
     */
    public function allowsSending(): bool
    {
        return $this === self::Approved;
    }
}
