<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Cihazın kendini tanıttığı değerler: model, seri numarası, JunOS sürümü.
 *
 * Yalnızca Zabbix'ten gelselerdi Zabbix keşfi çalışana kadar boş kalırlardı.
 * NETCONF ilk oturumda veriyor; tek kaynağa bağlı kalmamak için buradan da
 * okunur.
 */
class DeviceFactsReader
{
    /**
     * @return array{model: ?string, serial_number: ?string, junos_version: ?string, hostname: ?string, clock_offset_seconds: ?int}
     *
     * @throws NetconfException
     */
    public function facts(NetconfSession $session): array
    {
        $software = $session->rpc('<get-software-information/>');
        $chassis = $session->rpc('<get-chassis-inventory/>');

        return [
            'clock_offset_seconds' => $this->clockOffset($session),
            'model' => $this->text($chassis, '//*[local-name()="chassis"]/*[local-name()="description"]')
                ?? $this->text($software, '//*[local-name()="product-model"]'),
            'serial_number' => $this->text($chassis, '//*[local-name()="chassis"]/*[local-name()="serial-number"]'),
            'junos_version' => $this->version($software),
            'hostname' => $this->text($software, '//*[local-name()="host-name"]'),
        ];
    }

    /**
     * Cihaz saatinin PANELDEN sapması, saniye. Okunamazsa null.
     *
     * NTP sunucusu tanımlı bir cihazın saati de yıllarca geride kalabilir
     * (bkz. DeviceClockSynchronizer); bu sapma gösterilmezse yalnızca commit
     * geçmişindeki tarihlerden fark edilir.
     *
     * Saat yanlışsa commit geçmişi, günlükler ve panelin kendi kayıtlarıyla
     * yapılan her eşleştirme yanlış zamana düşer.
     *
     * Okuma BAŞARISIZ OLURSA iş durmaz: bu bir yan bilgi, eşitlemenin amacı
     * değil.
     */
    private function clockOffset(NetconfSession $session): ?int
    {
        try {
            $reply = $session->rpc('<get-system-uptime-information/>');
        } catch (Throwable) {
            return null;
        }

        $metin = $this->text(
            $reply,
            '//*[local-name()="current-time"]/*[local-name()="date-time"]',
        );

        if ($metin === null) {
            return null;
        }

        try {
            return (int) round(CarbonImmutable::parse($metin)->diffInSeconds(CarbonImmutable::now()));
        } catch (Throwable) {
            // Cihazın tarih biçimi beklenmedikse sapma bilinmiyor demektir.
            return null;
        }
    }

    /**
     * Sürüm ayrı bir alanda gelmez; paket açıklamalarında köşeli parantez
     * içinde durur: "JUNOS Base OS boot [12.3R12.4]".
     */
    private function version(\SimpleXMLElement $software): ?string
    {
        foreach ($software->xpath('//*[local-name()="package-information"]/*[local-name()="comment"]') ?: [] as $comment) {
            if (preg_match('/\[([^\]]+)\]/', (string) $comment, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private function text(\SimpleXMLElement $element, string $xpath): ?string
    {
        $found = $element->xpath($xpath) ?: [];
        $value = trim((string) ($found[0] ?? ''));

        return $value === '' ? null : $value;
    }
}
