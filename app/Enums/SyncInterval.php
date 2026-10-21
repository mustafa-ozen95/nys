<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Eşitleme işlerinin çalışma sıklığı.
 *
 * Serbest metin yerine kapalı bir liste: zamanlayıcıya geçersiz bir cron
 * ifadesi yazılamaz ve "kapalı" da bir seçenektir. Değer cron ifadesine
 * çevrilir; saniyelik olanlar Laravel'in kendi yardımcılarını kullanır.
 */
enum SyncInterval: string
{
    case Off = 'off';
    case ThirtySeconds = '30s';
    case Minute = '1m';
    case FiveMinutes = '5m';
    case FifteenMinutes = '15m';
    case ThirtyMinutes = '30m';
    case Hour = '1h';
    case SixHours = '6h';
    case Daily = '1d';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Kapalı',
            self::ThirtySeconds => '30 saniyede bir',
            self::Minute => 'Dakikada bir',
            self::FiveMinutes => '5 dakikada bir',
            self::FifteenMinutes => '15 dakikada bir',
            self::ThirtyMinutes => '30 dakikada bir',
            self::Hour => 'Saatte bir',
            self::SixHours => '6 saatte bir',
            self::Daily => 'Günde bir',
        };
    }

    /**
     * Kapalı olan sıklık listede sönük görünür; çalışanlar vurgulanır.
     */
    public function badge(): BadgeVariant
    {
        return $this === self::Off ? BadgeVariant::Gray : BadgeVariant::Green;
    }

    /**
     * Zamanlayıcıya verilecek cron ifadesi. Kapalı ve 30 saniye burada
     * karşılık bulmaz; ikisini de çağıran ayrıca ele alır.
     */
    public function cron(): ?string
    {
        return match ($this) {
            self::Off, self::ThirtySeconds => null,
            self::Minute => '* * * * *',
            self::FiveMinutes => '*/5 * * * *',
            self::FifteenMinutes => '*/15 * * * *',
            self::ThirtyMinutes => '*/30 * * * *',
            self::Hour => '0 * * * *',
            self::SixHours => '0 */6 * * *',
            self::Daily => '0 3 * * *',
        };
    }
}
