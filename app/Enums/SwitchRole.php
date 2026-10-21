<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * İki rol: omurga ve erişim. Dağıtım katmanı yok; olmayan bir rolü
 * listede tutmak kullanıcıya yanlış seçenek sunar.
 */
enum SwitchRole: string
{
    case Core = 'core';
    case Access = 'access';

    public function label(): string
    {
        return match ($this) {
            self::Core => 'Omurga',
            self::Access => 'Erişim',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Core => BadgeVariant::Purple,
            self::Access => BadgeVariant::Cyan,
        };
    }

    /**
     * Listede sıra. Küçük olan üstte.
     *
     * Omurga üstte durur: bir omurga kesintisi altındaki bütün erişim
     * switchlerini etkiler, o yüzden önce ona bakılır. Rol ROLDÜR, durum
     * değil — sıralamanın ikinci anahtarı hâlâ durumdur, böylece sorunlu
     * omurga sağlıklı omurgaların da üstünde kalır.
     */
    public function listingOrder(): int
    {
        return match ($this) {
            self::Core => 0,
            self::Access => 1,
        };
    }
}
