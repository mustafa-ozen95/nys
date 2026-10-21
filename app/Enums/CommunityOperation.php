<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * "then community <işlem> <ad>" işlemi.
 *
 * Cihazda ölçüldü (test1, commit check): `then { community add TOPLULUK-A; }`
 * kabul edildi. Silme işlemi omurga yapılandırmalarında da kullanılır
 * (ör. omurga-1).
 *
 * XML biçimi cihazdan okunan yapının aynısıdır:
 * <community><add/><community-name>ad</community-name></community>
 */
enum CommunityOperation: string
{
    case Add = 'add';
    case Set = 'set';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Add => 'Ekle (add)',
            self::Set => 'Yerine yaz (set)',
            self::Delete => 'Sil (delete)',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Add => BadgeVariant::Green,
            self::Set => BadgeVariant::Blue,
            self::Delete => BadgeVariant::Red,
        };
    }
}
