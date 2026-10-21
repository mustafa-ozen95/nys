<?php

declare(strict_types=1);

namespace App\Support\Net;

/**
 * AS numarasının TEK yazımı.
 *
 * Aynı numara "AS64496", "as64496" ve "64496" olarak yazılabiliyor. Üç yazım
 * da kabul edilir ama panelde tek biçim dolaşır: büyük harfli `AS` öneki.
 * Aksi hâlde aynı ASN listede seçili görünmez, önbellekte iki ayrı anahtar
 * açar ve mailde her seferinde başka türlü basılır.
 *
 * Normalleştirme burada toplanır; `ltrim($asn, 'ASas')` gibi bir satır başka
 * sınıflarda tekrarlanmaz (bkz. .ai/rules/modulerlik.md, "aynı soruyu ikinci
 * bir yerde cevaplıyor muyum").
 */
final readonly class Asn
{
    /** 32 bit AS numarası aralığının üst sınırı (RFC 6793). */
    public const MAX = 4294967295;

    /**
     * "as64496" → "AS64496". Geçersizse null.
     */
    public static function normalize(string $value): ?string
    {
        $digits = self::digits($value);

        return $digits === null ? null : 'AS'.$digits;
    }

    /**
     * "AS64496" → "64496". Geçersizse null.
     *
     * Sorgu uçları (RIPEstat, IRRd) numarayı çıplak ya da önekli isteyebilir;
     * ikisini de buradan alırlar.
     */
    public static function digits(string $value): ?string
    {
        $trimmed = trim($value);

        if (preg_match('/^(?:AS)?(\d{1,10})$/i', $trimmed, $matches) !== 1) {
            return null;
        }

        $number = ltrim($matches[1], '0');
        $number = $number === '' ? '0' : $number;

        return (int) $number > self::MAX ? null : $number;
    }

    public static function isValid(string $value): bool
    {
        return self::digits($value) !== null;
    }
}
