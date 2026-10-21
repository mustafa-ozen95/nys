<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Format\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    #[DataProvider('values')]
    public function test_it_formats_seconds(?int $seconds, string $expected): void
    {
        $this->assertSame($expected, Duration::format($seconds));
    }

    /**
     * @return array<string, array{?int, string}>
     */
    public static function values(): array
    {
        return [
            'saniye' => [45, '45 sn'],
            'dakika esigi' => [60, '1 dk'],
            'saat ve dakika' => [3900, '1 sa 5 dk'],
            'tam saat' => [7200, '2 sa'],
            'gun' => [86400, '1 g'],
            'gun ve saat' => [93600, '1 g 2 sa'],
            'gunlerde dakika gosterilmez' => [90061, '1 g 1 sa'],
            'null' => [null, '—'],
            'negatif' => [-1, '—'],
        ];
    }
}
