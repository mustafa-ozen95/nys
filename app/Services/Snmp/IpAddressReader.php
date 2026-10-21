<?php

declare(strict_types=1);

namespace App\Services\Snmp;

use App\Models\NetworkSwitch;

/**
 * Switchin arayüzlerine tanımlı IPv4 adresleri (IP-MIB ipAddrTable).
 *
 * Adres OID indeksinde durur; maske ayrı sütundadır.
 */
class IpAddressReader
{
    public function __construct(private SnmpClient $snmp) {}

    /**
     * @return array<int, array{address: string, prefix_length: int, if_index: int, interface: ?string}>
     */
    public function addresses(NetworkSwitch $switch): array
    {
        $oids = config('snmp.oids');

        $ifIndexes = $this->snmp->walk($switch, $oids['ip_ifindex']);
        $netmasks = $this->snmp->walk($switch, $oids['ip_netmask']);
        $names = $this->snmp->walk($switch, $oids['if_name']);

        $addresses = [];

        foreach ($ifIndexes as $address => $ifIndex) {
            $mask = $netmasks[$address] ?? null;
            $prefix = $mask === null ? null : $this->prefixLength($mask);

            $interface = ($names[$ifIndex] ?? '') ?: null;

            if ($prefix === null || filter_var($address, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            if ($this->isInternal($interface)) {
                continue;
            }

            $addresses[] = [
                'address' => $address,
                'prefix_length' => $prefix,
                'if_index' => (int) $ifIndex,
                'interface' => $interface,
            ];
        }

        return $addresses;
    }

    /**
     * Juniper'ın iç arayüzleri envantere alınmaz.
     */
    private function isInternal(?string $interface): bool
    {
        if ($interface === null) {
            return false;
        }

        foreach (config('snmp.ignored_interfaces') as $prefix) {
            if (str_starts_with($interface, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "255.255.255.248" -> 29
     */
    private function prefixLength(string $netmask): ?int
    {
        $long = ip2long($netmask);

        if ($long === false) {
            return null;
        }

        $binary = decbin($long);
        $prefix = substr_count($binary, '1');

        // Maske bitişik olmalı: 255.255.0.255 gibi bir değer kabul edilmez.
        return str_contains(str_pad($binary, 32, '0', STR_PAD_LEFT), '01') ? null : $prefix;
    }
}
