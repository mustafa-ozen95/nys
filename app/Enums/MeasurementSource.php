<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ölçüm verisinin (durum, uptime, CPU, sıcaklık, port hızı, anlık trafik)
 * hangi kaynaktan okunacağı.
 *
 * Yapılandırma her zaman NETCONF'tan gelir ve bu seçim onu etkilemez; burada
 * konu yalnızca ÖLÇÜMDÜR. Zabbix çöktüğünde ya da elle devre dışı
 * bırakıldığında panel körelmesin diye cihazın kendisi de bir kaynaktır.
 */
enum MeasurementSource: string
{
    case Zabbix = 'zabbix';
    case Netconf = 'netconf';
    case Snmp = 'snmp';

    public function label(): string
    {
        return match ($this) {
            self::Zabbix => 'İzleme sistemi (Zabbix)',
            self::Netconf => 'Cihazın kendisi (NETCONF)',
            self::Snmp => 'Cihazın kendisi (SNMP)',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Zabbix => 'Geçmiş grafikleri dahil en zengin veri. Zabbix erişilemezse yedeğe düşülür.',
            self::Netconf => 'Cihaza doğrudan sorulur. Geçmiş grafiği panelin kendi örneklemesinden üretilir.',
            self::Snmp => 'Cihaza doğrudan sorulur. NETCONF kimliği olmayan cihazlarda da çalışır.',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Zabbix => BadgeVariant::Blue,
            self::Netconf => BadgeVariant::Green,
            self::Snmp => BadgeVariant::Cyan,
        };
    }

    /**
     * Cihaza doğrudan giden kaynaklar. Bunlarda geçmiş verisi yoktur;
     * grafik panelin kendi örneklemesinden çizilir.
     */
    public function readsDevice(): bool
    {
        return $this !== self::Zabbix;
    }
}
