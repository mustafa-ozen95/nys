<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Grubun graceful-restart davranışı.
 *
 * Üç durum vardır ve ikisi aynı şey değildir:
 *
 *   default  — cihazda satır yok; Junos kendi genel ayarını uygular.
 *   enable   — "graceful-restart" yazılır.
 *   disable  — "graceful-restart disable" yazılır.
 *
 * "default" ile "disable" karıştırılırsa, genel ayarı açık olan bir cihazda
 * grup sessizce açık kalır; oysa kullanıcı kapattığını sanır.
 */
enum BgpGracefulRestart: string
{
    case Inherit = 'default';
    case Enabled = 'enable';
    case Disabled = 'disable';

    public function label(): string
    {
        return match ($this) {
            self::Inherit => 'Cihazın genel ayarı',
            self::Enabled => 'Açık',
            self::Disabled => 'Kapalı',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Inherit => BadgeVariant::Gray,
            self::Enabled => BadgeVariant::Green,
            self::Disabled => BadgeVariant::Orange,
        };
    }
}
