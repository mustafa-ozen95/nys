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
        $measured = $switch->device_capabilities[$key] ?? null;

        return is_array($measured) ? array_values(array_map(strval(...), $measured)) : null;
    }

    /**
     * Bu değer cihaza yazılabilir mi? Ölçüm yoksa evet.
     */
    public function supports(NetworkSwitch $switch, string $key, string $value): bool
    {
        $measured = $this->values($switch, $key);

        return $measured === null || in_array($value, $measured, true);
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
        $scope = PortSpeedCapability::scope($interfaceName);
        $measured = $this->values($switch, 'port.speeds');

        if ($scope === null || $measured === null) {
            return null;
        }

        $prefix = $scope.':';

        $speeds = array_values(array_map(
            static fn (string $key): string => substr($key, strlen($prefix)),
            array_filter($measured, static fn (string $a): bool => str_starts_with($a, $prefix)),
        ));

        // Bu PIC hiç ölçülmemiş: daraltma yapılmaz.
        return $speeds === [] ? null : $speeds;
    }

    /**
     * Port hızı seçeneklerini bu PORTA göre daraltır.
     *
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    public function filterSpeeds(NetworkSwitch $switch, string $interfaceName, array $options): array
    {
        $measured = $this->speedsFor($switch, $interfaceName);

        return $measured === null ? $options : array_intersect_key($options, array_flip($measured));
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
        $measured = $this->values($switch, $key);

        if ($measured === null) {
            return $options;
        }

        return array_intersect_key($options, array_flip($measured));
    }
}
