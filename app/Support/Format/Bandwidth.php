<?php

declare(strict_types=1);

namespace App\Support\Format;

/**
 * Bit/saniye değerlerini okunabilir birimlere çevirir.
 */
final class Bandwidth
{
    private const UNITS = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];

    private const STEP = 1000;

    public static function format(int|float|null $bitsPerSecond, int $precision = 2): string
    {
        if ($bitsPerSecond === null) {
            return '—';
        }

        $value = (float) $bitsPerSecond;

        if ($value < 0) {
            return '—';
        }

        $unitIndex = 0;

        while ($value >= self::STEP && $unitIndex < count(self::UNITS) - 1) {
            $value /= self::STEP;
            $unitIndex++;
        }

        $decimals = $unitIndex === 0 ? 0 : $precision;

        return number_format($value, $decimals, ',', '.').' '.self::UNITS[$unitIndex];
    }

    /**
     * Bir dizi değer için ortak ölçek: hangi birime bölünecek ve adı ne.
     *
     * Grafik ekseninin birimi burada belirlenir; tarayıcı yalnızca bölme ve
     * etiket yazma işini yapar.
     *
     * @return array{divisor: int, unit: string}
     */
    public static function scaleFor(int|float|null $maximum): array
    {
        $value = (float) ($maximum ?? 0);
        $divisor = 1;
        $unitIndex = 0;

        while ($value >= self::STEP && $unitIndex < count(self::UNITS) - 1) {
            $value /= self::STEP;
            $divisor *= self::STEP;
            $unitIndex++;
        }

        return ['divisor' => $divisor, 'unit' => self::UNITS[$unitIndex]];
    }

    /**
     * Bağlantı hızına göre doluluk yüzdesi (0-100).
     */
    public static function utilisation(int|float|null $bitsPerSecond, int|float|null $capacityMbps): ?float
    {
        if ($bitsPerSecond === null || $capacityMbps === null || $capacityMbps <= 0) {
            return null;
        }

        $capacityBps = (float) $capacityMbps * 1_000_000;

        return min(100.0, round(((float) $bitsPerSecond / $capacityBps) * 100, 1));
    }
}
