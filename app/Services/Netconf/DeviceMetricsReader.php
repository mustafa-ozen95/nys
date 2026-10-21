<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Support\Measurement\DeviceMeasurement;
use SimpleXMLElement;

/**
 * Cihazın anlık ölçümleri: uptime, CPU, bellek, sıcaklık.
 *
 * Kaynak iki RPC'dir ve ikisi de EX4200/12.3R12.4 üzerinde doğrulandı:
 *
 *   get-route-engine-information — cpu-idle, memory-buffer-utilization,
 *     model, serial-number ve up-time. Tek çağrıda beş alan.
 *   get-environment-information — bütün sıcaklık sensörleri. RE'nin kendi
 *     sıcaklığı en yüksek olan değildir (PFE, RE'den birkaç derece sıcak olabilir);
 *     izleme sisteminin gösterdiği değerle aynı olsun diye en yükseği alınır.
 *
 * CPU kullanımı cihazda YOKTUR; boşta kalma yüzdesi vardır. Kullanım
 * 100 - cpu-idle olarak hesaplanır.
 *
 * Sıcaklık ve süre gibi sayısal değerler metnin içinde değil junos: ön ekli
 * niteliktedir ("39 degrees C / 102 degrees F" metninin yanında celsius="39");
 * metin ayrıştırmak yerine nitelik okunur.
 */
class DeviceMetricsReader
{
    /**
     * @throws NetconfException
     */
    public function metrics(NetconfSession $session): DeviceMeasurement
    {
        $engine = $session->rpc('<get-route-engine-information/>');
        $idle = $this->int($engine, '//*[local-name()="cpu-idle"]');

        return new DeviceMeasurement(
            reachable: true,
            uptimeSeconds: $this->seconds($engine, '//*[local-name()="route-engine"]/*[local-name()="up-time"]'),
            cpuUsage: $idle === null ? null : max(0, min(100, 100 - $idle)),
            memoryUsage: $this->int($engine, '//*[local-name()="memory-buffer-utilization"]'),
            temperature: $this->temperature($session),
            model: $this->text($engine, '//*[local-name()="route-engine"]/*[local-name()="model"]'),
            serialNumber: $this->text($engine, '//*[local-name()="route-engine"]/*[local-name()="serial-number"]'),
        );
    }

    /**
     * Sensörlerin en yükseği. Sıcaklık okuyamayan sensörler (fan, güç
     * kaynağı) niteliği taşımaz ve elenir.
     *
     * @throws NetconfException
     */
    private function temperature(NetconfSession $session): ?int
    {
        $environment = $session->rpc('<get-environment-information/>');
        $readings = [];

        foreach ($environment->xpath('//*[local-name()="temperature"]') ?: [] as $sensor) {
            $celsius = $this->attribute($sensor, 'celsius');

            if ($celsius !== null) {
                $readings[] = $celsius;
            }
        }

        return $readings === [] ? null : max($readings);
    }

    private function seconds(SimpleXMLElement $document, string $xpath): ?int
    {
        $found = $document->xpath($xpath) ?: [];

        return isset($found[0]) ? $this->attribute($found[0], 'seconds') : null;
    }

    /**
     * junos: ön ekli sayısal nitelik. Ön ekin URI'si JunOS sürümünü içerdiği
     * için sabit değildir; bütün ad alanları taranır.
     */
    private function attribute(SimpleXMLElement $element, string $name): ?int
    {
        foreach ($element->getNamespaces(true) as $uri) {
            $value = $element->attributes($uri)?->{$name};

            if ($value !== null) {
                return (int) $value;
            }
        }

        return null;
    }

    private function int(SimpleXMLElement $document, string $xpath): ?int
    {
        $text = $this->text($document, $xpath);

        return $text === null ? null : (int) $text;
    }

    private function text(SimpleXMLElement $document, string $xpath): ?string
    {
        $found = $document->xpath($xpath) ?: [];
        $value = trim((string) ($found[0] ?? ''));

        return $value === '' ? null : $value;
    }
}
