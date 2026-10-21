<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Junos'un önceden tanımlı giriş sınıfları.
 *
 * Serbest metin kabul edilmez: cihaz tanımsız sınıfı reddediyor —
 * "Undefined class 'yonetici' for user 'dogrulama'" (jn32-test, 12.3R12.4).
 *
 * Cihazda elle tanımlanmış özel bir sınıf varsa okunur ve gösterilir ama
 * panelden yeni bir hesaba atanamaz; panel yalnızca kesin bildiğini önerir.
 */
enum JunosUserClass: string
{
    case SuperUser = 'super-user';
    case Operator = 'operator';
    case ReadOnly = 'read-only';
    case Unauthorized = 'unauthorized';

    public function label(): string
    {
        return match ($this) {
            self::SuperUser => 'Süper kullanıcı (tam yetki)',
            self::Operator => 'Operatör (yapılandırma yazamaz)',
            self::ReadOnly => 'Salt okunur',
            self::Unauthorized => 'Yetkisiz (giriş yok)',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::SuperUser => BadgeVariant::Red,
            self::Operator => BadgeVariant::Orange,
            self::ReadOnly => BadgeVariant::Gray,
            self::Unauthorized => BadgeVariant::Gray,
        };
    }

    /** Cihazı yapılandırabilen sınıflar. */
    public function canConfigure(): bool
    {
        return $this === self::SuperUser;
    }
}
