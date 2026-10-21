<?php

declare(strict_types=1);

namespace App\Support\Zabbix;

/**
 * Zabbix item anahtarlarından arayüz bilgisi çıkarır.
 *
 * Örnek anahtar: net.if.in[ifHCInOctets.518]
 * Örnek item adı: Interface ge-0/0/1(): Bits received
 */
final class ItemKey
{
    /**
     * Anahtarın sonundaki SNMP ifIndex değerini döndürür.
     */
    public static function ifIndex(string $key): ?int
    {
        if (preg_match('/\.(\d+)\]$/', $key, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Item adından arayüz adını çıkarır. Alt arayüzler (ge-0/0/1.0) elenir.
     */
    public static function portName(string $itemName): ?string
    {
        if (preg_match('/Interface\s+([A-Za-z0-9\/\-.:]+)/', $itemName, $matches) !== 1) {
            return null;
        }

        $name = rtrim($matches[1], '(:');

        return str_contains($name, '.') ? null : $name;
    }

    /**
     * Zabbix şablonu arayüz açıklamasını (ifAlias) item adının parantezine
     * yerleştirir: "Interface ge-0/1/2(UP-Link-2/1): Speed". Boş parantez
     * switchte açıklama tanımlı olmadığı anlamına gelir.
     */
    public static function portAlias(string $itemName): ?string
    {
        if (preg_match('/Interface\s+[A-Za-z0-9\/\-.:]+\(([^)]*)\)/', $itemName, $matches) !== 1) {
            return null;
        }

        $alias = trim($matches[1]);

        return $alias === '' ? null : $alias;
    }

    public static function startsWithAny(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
