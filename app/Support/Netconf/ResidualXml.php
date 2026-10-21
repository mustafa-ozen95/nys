<?php

declare(strict_types=1);

namespace App\Support\Netconf;

use SimpleXMLElement;

/**
 * Panelin TANIMADIĞI yapılandırma satırlarını ham hâliyle taşır.
 *
 * Junos yapılandırma dili panelin modellediğinden çok daha geniştir ve hep
 * öyle kalacak. Asıl risk de buydu: panel anladığını geri yazıyor,
 * anlamadığını sessizce siliyordu. O yüzden nesne güncellenirken
 * silinip yeniden yazıldığında, panelin okumadığı her satır kayboluyordu —
 * bir politikanın varsayılan eylemi, bir filtrenin interface-specific'i,
 * bir route'un tag'i.
 *
 * Çözüm sıralaması şudur ve ikisi birden gerekir:
 *
 *   1. Modellenen alan kümesi genişletilir — kullanıcı gerçekten YÖNETEBİLSİN.
 *   2. Geri kalan uzun kuyruk ham XML olarak saklanır ve geri yazılırken
 *      olduğu gibi yeniden basılır — hiçbir şey KAYBOLMASIN.
 *
 * Çakışma olamaz: artık yalnızca "bilinen" listesinde OLMAYAN çocukları
 * toplar, panel de yalnızca bilinenleri üretir.
 *
 * Cihazda doğrulandı (test1, commit check): okunan düğümün asXML() çıktısı
 * aynı yükleme içinde geri gönderildiğinde cihaz kabul ediyor.
 */
final class ResidualXml
{
    /**
     * Düğümün tanınmayan çocuklarını ham XML olarak toplar.
     *
     * @param  list<string>  $known  Panelin ürettiği/anladığı eleman adları
     * @return list<string>
     */
    public static function collect(SimpleXMLElement $node, array $known): array
    {
        $residual = [];

        foreach ($node->children() as $child) {
            if (in_array($child->getName(), $known, true)) {
                continue;
            }

            $xml = self::clean((string) $child->asXML());

            if ($xml !== '') {
                $residual[] = $xml;
            }
        }

        return $residual;
    }

    /**
     * Saklanan parçaları geri yazılacak gövdeye ekler.
     *
     * @param  list<string>|null  $residual
     */
    public static function emit(?array $residual): string
    {
        return $residual === null ? '' : implode('', $residual);
    }

    /**
     * Ad listesi + artık = düğümün tamamı mı?
     *
     * Ekranda "panelde gösterilmeyen N satır korunuyor" demek için kullanılır;
     * kullanıcı neyin taşındığını bilmeli.
     *
     * @param  list<string>|null  $residual
     * @return list<string> Korunan satırların eleman adları
     */
    public static function names(?array $residual): array
    {
        if ($residual === null) {
            return [];
        }

        $names = [];

        foreach ($residual as $xml) {
            if (preg_match('/^<\s*([A-Za-z0-9_.:-]+)/', $xml, $eslesme) === 1) {
                $names[] = $eslesme[1];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * SimpleXML, alt düğümü tek başına dışa aktarırken kökten devraldığı ad
     * alanı bildirimini de yazar. Junos yükleme bunu kabul eder ama fark
     * çıktısını kirletir ve karşılaştırmayı zorlaştırır; temizlenir.
     *
     * Diğer öznitelikler KORUNUR: "inactive" gibi işaretler yapılandırmanın
     * anlamının parçasıdır (deactivate edilmiş satır) ve kaybolmamalıdır.
     */
    private static function clean(string $xml): string
    {
        $xml = (string) preg_replace('/\s+xmlns(:[A-Za-z0-9_-]+)?="[^"]*"/', '', $xml);

        return trim($xml);
    }
}
