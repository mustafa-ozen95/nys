<?php

declare(strict_types=1);

namespace App\Services\Measurement;

use RuntimeException;
use Throwable;

/**
 * Ölçüm kaynağına ulaşılamadı.
 *
 * "Cihaz çevrimdışı" ile karıştırılmamalıdır: bu istisna, SORAMADIĞIMIZI
 * söyler. Çözümleyici bunu görünce sıradaki kaynağa geçer; hiçbir kaynak
 * cevap veremezse durum "bilinmiyor" olur.
 */
class MeasurementUnavailable extends RuntimeException
{
    public static function from(Throwable $previous): self
    {
        return new self($previous->getMessage(), previous: $previous);
    }
}
