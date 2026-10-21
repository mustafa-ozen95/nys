<?php

declare(strict_types=1);

namespace App\Services\Capabilities;

use App\Models\NetworkSwitch;

/**
 * Ölçülmüş yeteneklerin okunduğu tek kapı.
 *
 * Ekran da, doğrulama da, servis de aynı soruyu buraya sorar. İkinci bir
 * yerde cevaplanırsa biri değiştiğinde öteki sessizce ayrışır
 * (bkz. .ai/rules/modularity.md).
 *
 * BİLİNMEYEN = SERBEST. Henüz ölçülmemiş bir cihazda seçenek KISITLANMAZ:
 * sonda çalışmadı diye çalışan bir özelliğin kaybolması, yanlış bir değeri
 * denemekten daha kötüdür. Cihaz zaten reddederse hata görünür ve kayda
 * geçer; sessiz bir kayıp görünmez.
 */
class DeviceCapabilities
{
    /**
     * Cihazın kabul ettiği değerler; hiç ölçülmemişse null.
     *
     * @return list<string>|null
     */
    public function values(NetworkSwitch $switch, string $key): ?array
    {
        $olculen = $switch->device_capabilities[$key] ?? null;

        return is_array($olculen) ? array_values(array_map(strval(...), $olculen)) : null;
    }

    /**
     * Bu değer cihaza yazılabilir mi? Ölçüm yoksa evet.
     */
    public function supports(NetworkSwitch $switch, string $key, string $value): bool
    {
        $olculen = $this->values($switch, $key);

        return $olculen === null || in_array($value, $olculen, true);
    }

    /**
     * Bu PORTUN kabul ettiği hızlar; ölçüm yoksa null.
     *
     * Kısıt cihaz genelinde değil PIC başına (bkz. PortSpeedCapability):
     * yerleşik bakır portlar 1 Gbps'i zorla kabul etmezken aynı cihazın SFP
     * yuvaları kabul ediyor. Cihaz genelinde saklansaydı uplinklerde geçerli
     * olan bir seçenek gizlenirdi.
     *
     * @return list<string>|null
     */
    public function speedsFor(NetworkSwitch $switch, string $interfaceName): ?array
    {
        $kapsam = PortSpeedCapability::scope($interfaceName);
        $olculen = $this->values($switch, 'port.speeds');

        if ($kapsam === null || $olculen === null) {
            return null;
        }

        $onek = $kapsam.':';

        $hizlar = array_values(array_map(
            static fn (string $anahtar): string => substr($anahtar, strlen($onek)),
            array_filter($olculen, static fn (string $a): bool => str_starts_with($a, $onek)),
        ));

        // Bu PIC hiç ölçülmemiş: daraltma yapılmaz.
        return $hizlar === [] ? null : $hizlar;
    }

    /**
     * Port hızı seçeneklerini bu PORTA göre daraltır.
     *
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    public function filterSpeeds(NetworkSwitch $switch, string $interfaceName, array $options): array
    {
        $olculen = $this->speedsFor($switch, $interfaceName);

        return $olculen === null ? $options : array_intersect_key($options, array_flip($olculen));
    }

    /**
     * Seçenek listesini ölçüme göre daraltır.
     *
     * Ölçüm yoksa liste OLDUĞU GİBİ döner. Ölçüm varsa yalnızca cihazın
     * kabul ettiği anahtarlar kalır — kullanıcı seçemeyeceği bir seçeneği
     * seçip "invalid value" yemesin.
     *
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    public function filter(NetworkSwitch $switch, string $key, array $options): array
    {
        $olculen = $this->values($switch, $key);

        if ($olculen === null) {
            return $options;
        }

        return array_intersect_key($options, array_flip($olculen));
    }
}
