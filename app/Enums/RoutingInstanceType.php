<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Yönlendirme tablosunun türü.
 *
 * Filtre tabanlı yönlendirme (FBF) için "forwarding" kullanılır: firewall
 * terimi trafiği "then routing-instance" ile bu tabloya atar ve tablo kendi
 * varsayılan yolunu taşır.
 *
 * Türlerin tamamı cihazda ölçülmedi; ölçülen ikisi burada. Junos'un
 * desteklediği başka türler (vrf, l2vpn…) panelde şimdilik yok — yazılmamış
 * bir türü seçenek olarak sunmak, cihazın reddedeceği bir seçim sunmaktır.
 */
enum RoutingInstanceType: string
{
    /** Yalnızca yönlendirme tablosu; FBF'nin kullandığı tür (test1'de ölçüldü). */
    case Forwarding = 'forwarding';

    /** Kendi yönlendirme protokolü olan bağımsız tablo (test1'de ölçüldü). */
    case VirtualRouter = 'virtual-router';

    public function label(): string
    {
        return match ($this) {
            self::Forwarding => 'Yönlendirme tablosu (forwarding)',
            self::VirtualRouter => 'Bağımsız yönlendirici (virtual-router)',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Forwarding => BadgeVariant::Cyan,
            self::VirtualRouter => BadgeVariant::Purple,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
