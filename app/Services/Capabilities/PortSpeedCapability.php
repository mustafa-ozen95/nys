<?php

declare(strict_types=1);

namespace App\Services\Capabilities;

use App\Models\NetworkSwitch;
use App\Services\Junos\JunosDialect;

/**
 * Portun kabul ettiği hız değerleri — PIC BAŞINA.
 *
 * Kısıt cihazın tamamına değil, portun bulunduğu PIC'e ait. EX2300'de
 * ölçüldü (test4, 24.4R2-S4):
 *
 *   ge-0/0/0, ge-0/0/12, ge-0/0/23  ->  "speed 1g" RED (invalid value)
 *   ge-0/1/1, ge-0/1/3, ge-0/2/0    ->  "speed 1g" KABUL
 *   mge-0/0/1                       ->  "speed auto" RED
 *
 * Yerleşik 1000BASE-T bakır portlarda hız zorla ayarlanamaz (gigabit
 * yalnızca otomatik anlaşmayla kurulur); SFP yuvalarında ayarlanabilir.
 * Ölçümü cihaz genelinde saklamak, uplink portlarında GEÇERLİ olan bir
 * seçeneği gizlerdi — ilk sürüm tam olarak bunu yapıyordu.
 *
 * Bu yüzden anahtar kapsamlıdır: "ge-0/0:100" gibi. Kapsam, arayüz adının
 * ortam + fpc/pic parçasıdır.
 */
final class PortSpeedCapability implements DeviceCapability
{
    public function key(): string
    {
        return 'port.speeds';
    }

    public function label(): string
    {
        return 'Port hızı';
    }

    /**
     * Arayüz adının PIC kapsamı: "ge-0/0/23" -> "ge-0/0".
     *
     * Ad beklenen biçimde değilse null döner ve o port ölçüme girmez;
     * bilinmeyen kapsamda liste daralmaz.
     */
    public static function scope(string $interfaceName): ?string
    {
        return preg_match('#^([a-z]+)-(\d+)/(\d+)/#', $interfaceName, $m) === 1
            ? "{$m[1]}-{$m[2]}/{$m[3]}"
            : null;
    }

    /**
     * Kapsamlı değer anahtarı.
     */
    public static function candidateKey(string $scope, string $speed): string
    {
        return "{$scope}:{$speed}";
    }

    public function candidates(JunosDialect $dialect, NetworkSwitch $switch): array
    {
        $adaylar = [];

        foreach ($this->scopes($switch) as $kapsam => $ornek) {
            foreach ($dialect->speedValues() as $deger => $eleman) {
                $adaylar[self::candidateKey($kapsam, (string) $deger)] =
                    '<interfaces><interface><name>'.$ornek.'</name>'
                    .($dialect->usesPlainSpeed()
                        ? '<speed>'.$eleman.'</speed>'
                        : '<ether-options><speed><'.$eleman.'/></speed></ether-options>')
                    .'</interface></interfaces>';
            }
        }

        return $adaylar;
    }

    /**
     * Cihazdaki PIC kapsamları ve her biri için TEMSİLCİ bir arayüz.
     *
     * Yalnızca envanterdeki portlar ölçülür: olmayan bir PIC'i sormak
     * cevabı olmayan bir soru sormaktır. Envanter boşsa hiçbir şey ölçülmez
     * ve liste daralmaz — ilk eşitlemeden sonra kendiliğinden dolar.
     *
     * @return array<string, string> kapsam => arayüz adı
     */
    private function scopes(NetworkSwitch $switch): array
    {
        $kapsamlar = [];

        foreach ($switch->ports()->orderBy('if_index')->pluck('name') as $ad) {
            $kapsam = self::scope((string) $ad);

            if ($kapsam !== null && ! isset($kapsamlar[$kapsam])) {
                $kapsamlar[$kapsam] = (string) $ad;
            }
        }

        return $kapsamlar;
    }
}
