<?php

declare(strict_types=1);

namespace App\Enums;

enum CredentialProtocol: string
{
    case Ssh = 'ssh';
    case Netconf = 'netconf';
    case SnmpV2c = 'snmp_v2c';
    case SnmpV3 = 'snmp_v3';

    public function label(): string
    {
        return match ($this) {
            self::Ssh => 'SSH',
            self::Netconf => 'NETCONF',
            self::SnmpV2c => 'SNMP v2c',
            self::SnmpV3 => 'SNMP v3',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Ssh => BadgeVariant::Blue,
            self::Netconf => BadgeVariant::Purple,
            self::SnmpV2c => BadgeVariant::Cyan,
            self::SnmpV3 => BadgeVariant::Cyan,
        };
    }
}
