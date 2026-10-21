<?php

declare(strict_types=1);

namespace App\Support\Format;

/**
 * Saniye cinsinden süreleri Türkçe kısa biçime çevirir.
 */
final class Duration
{
    private const MINUTE = 60;

    private const HOUR = 3600;

    private const DAY = 86400;

    public static function format(int|float|null $seconds): string
    {
        if ($seconds === null || $seconds < 0) {
            return '—';
        }

        $seconds = (int) $seconds;

        if ($seconds < self::MINUTE) {
            return $seconds.' sn';
        }

        $days = intdiv($seconds, self::DAY);
        $hours = intdiv($seconds % self::DAY, self::HOUR);
        $minutes = intdiv($seconds % self::HOUR, self::MINUTE);

        $parts = [];

        if ($days > 0) {
            $parts[] = $days.' g';
        }

        if ($hours > 0) {
            $parts[] = $hours.' sa';
        }

        if ($minutes > 0 && $days === 0) {
            $parts[] = $minutes.' dk';
        }

        return implode(' ', $parts);
    }
}
