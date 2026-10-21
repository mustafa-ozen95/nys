<?php

declare(strict_types=1);

namespace App\Enums;

enum SwitchStatus: string
{
    case Online = 'online';
    case Warning = 'warning';
    case Offline = 'offline';

    /*
     * Ölçüm alınamadı: cihaz çevrimdışı DEĞİL, durumu bilinmiyor.
     *
     * İkisini ayırmak zorunludur. İzleme sistemi çöktüğünde bütün filoyu
     * "çevrimdışı" göstermek yanlış alarmdır ve o sırada gerçekten düşen bir
     * cihazı görünmez kılar.
     */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Çevrimiçi',
            self::Warning => 'Uyarı',
            self::Offline => 'Çevrimdışı',
            self::Unknown => 'Bilinmiyor',
        };
    }

    /**
     * Listelerde önce ilgilenilmesi gereken durum gelir.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Warning => 0,
            self::Offline => 1,
            self::Unknown => 2,
            self::Online => 3,
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Online => BadgeVariant::Green,
            self::Warning => BadgeVariant::Yellow,
            self::Offline => BadgeVariant::Red,
            self::Unknown => BadgeVariant::Gray,
        };
    }
}
