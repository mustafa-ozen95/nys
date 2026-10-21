<?php

declare(strict_types=1);

namespace App\Support\Net;

/**
 * Q-BRIDGE port listesi çözücüsü.
 *
 * dot1qVlanStaticEgressPorts ve dot1qVlanStaticUntaggedPorts değerleri bir
 * oktet dizisidir: her bit bir köprü portudur. İlk oktetin en anlamlı biti
 * 1 numaralı porttur (RFC 4363, PortList).
 *
 *   0x80 = 1000 0000 -> port 1
 *   0x01 = 0000 0001 -> port 8
 *   0x40 0x02        -> port 2 ve port 15
 *
 * Ağ erişimi gerektirmez; bu yüzden ayrı ve tam test edilebilir.
 */
final class PortBitmap
{
    private const BITS_PER_OCTET = 8;

    /**
     * Ham oktet dizisinden köprü portu numaralarını çıkarır.
     *
     * @return array<int, int> artan sırada port numaraları
     */
    public static function ports(string $octets): array
    {
        $ports = [];

        for ($byte = 0, $length = strlen($octets); $byte < $length; $byte++) {
            $value = ord($octets[$byte]);

            if ($value === 0) {
                continue;
            }

            for ($bit = 0; $bit < self::BITS_PER_OCTET; $bit++) {
                // En anlamlı bit soldaki porttur.
                if (($value & (0x80 >> $bit)) !== 0) {
                    $ports[] = $byte * self::BITS_PER_OCTET + $bit + 1;
                }
            }
        }

        return $ports;
    }

    /**
     * SNMP kütüphaneleri değeri onaltılık dizge olarak verebilir
     * ("80 00 40" veya "0x800040"); ikisini de kabul eder.
     */
    public static function fromHex(string $hex): string
    {
        // Önce "0x" öneki atılır; yoksa baştaki sıfır tek haneli artık bırakır.
        $clean = preg_replace('/^\s*0x/i', '', trim($hex)) ?? '';
        $clean = preg_replace('/[^0-9a-f]/i', '', $clean) ?? '';

        if ($clean === '' || strlen($clean) % 2 !== 0) {
            return '';
        }

        return (string) hex2bin($clean);
    }

    /**
     * Port listesini yeniden oktet dizisine çevirir. Karşılaştırma ve test
     * kolaylığı için.
     *
     * @param  array<int, int>  $ports
     */
    public static function toOctets(array $ports, int $length = 0): string
    {
        $highest = $ports === [] ? 0 : max($ports);
        $length = max($length, (int) ceil($highest / self::BITS_PER_OCTET));
        $octets = array_fill(0, max($length, 1), 0);

        foreach ($ports as $port) {
            if ($port < 1) {
                continue;
            }

            $byte = intdiv($port - 1, self::BITS_PER_OCTET);
            $bit = ($port - 1) % self::BITS_PER_OCTET;
            $octets[$byte] |= 0x80 >> $bit;
        }

        return implode('', array_map(chr(...), $octets));
    }
}
