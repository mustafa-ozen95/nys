<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\Duplex;
use App\Enums\PortStatus;
use App\Support\Measurement\PortMeasurement;
use SimpleXMLElement;

/**
 * Arayüzlerin anlık ölçümü: oper durumu, anlaşılan hız ve dupleks, anlık
 * trafik.
 *
 * Tek RPC yeter: get-interface-information <media/>. EX4200/12.3R12.4
 * üzerinde doğrulandı — <extensive/> daha büyük bir yanıt üretir ama
 * anlaşılan hızı VERMEZ, <media/> verir.
 *
 * Hız ve dupleks otomatik anlaşmadaki portlarda <speed>Auto</speed> olarak
 * gelir; gerçek değer ethernet-autonegotiation altındaki karşı taraf
 * bilgisindedir ("1000 Mbps", "full-duplex"). Elle sabitlenmiş portlarda ise
 * <speed> doğrudan değeri taşır ("1000mbps"). İkisi de karşılanır.
 */
class InterfaceMetricsReader
{
    /**
     * @return array<int, PortMeasurement> ifIndex => ölçüm
     *
     * @throws NetconfException
     */
    public function metrics(NetconfSession $session): array
    {
        $reply = $session->rpc('<get-interface-information><media/></get-interface-information>');
        $measurements = [];

        foreach ($reply->xpath('//*[local-name()="physical-interface"]') ?: [] as $interface) {
            $ifIndex = $this->int($interface, 'snmp-index');

            // ifIndex'i olmayan arayüz panelde bir kayda karşılık gelmez.
            if ($ifIndex === null) {
                continue;
            }

            $measurements[$ifIndex] = new PortMeasurement(
                ifIndex: $ifIndex,
                operStatus: $this->operStatus($interface),
                speedMbps: $this->speed($interface),
                duplex: $this->duplex($interface),
                inBps: $this->bps($interface, 'input-bps'),
                outBps: $this->bps($interface, 'output-bps'),
            );
        }

        return $measurements;
    }

    private function operStatus(SimpleXMLElement $interface): PortStatus
    {
        return PortStatus::tryFrom(strtolower((string) $this->text($interface, 'oper-status'))) ?? PortStatus::Unknown;
    }

    /**
     * Mbps. "1000mbps" ve "1000 Mbps" biçimlerinin ikisi de gelir.
     */
    private function speed(SimpleXMLElement $interface): ?int
    {
        $value = $this->text($interface, 'speed');

        if ($value === null || strcasecmp($value, 'auto') === 0) {
            $value = $this->text($interface, 'ethernet-autonegotiation/link-partner-speed');
        }

        if ($value === null || preg_match('/(\d+)\s*mbps/i', $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function duplex(SimpleXMLElement $interface): ?Duplex
    {
        $value = $this->text($interface, 'duplex');

        if ($value === null || strcasecmp($value, 'auto') === 0) {
            $value = $this->text($interface, 'ethernet-autonegotiation/link-partner-duplexity');
        }

        if ($value === null) {
            return null;
        }

        return match (true) {
            str_starts_with(strtolower($value), 'full') => Duplex::Full,
            str_starts_with(strtolower($value), 'half') => Duplex::Half,
            default => null,
        };
    }

    private function bps(SimpleXMLElement $interface, string $element): ?float
    {
        $value = $this->text($interface, "traffic-statistics/{$element}");

        return $value === null ? null : (float) $value;
    }

    /**
     * Ad alanı bildirilmiş bir belgede yol araması yapılamaz; her adım
     * local-name() ile aranır.
     */
    private function text(SimpleXMLElement $element, string $path): ?string
    {
        $xpath = implode('/', array_map(
            static fn (string $step): string => '*[local-name()="'.$step.'"]',
            explode('/', $path),
        ));

        $found = $element->xpath($xpath) ?: [];
        $value = trim((string) ($found[0] ?? ''));

        return $value === '' ? null : $value;
    }

    private function int(SimpleXMLElement $element, string $path): ?int
    {
        $text = $this->text($element, $path);

        return $text === null ? null : (int) $text;
    }
}
