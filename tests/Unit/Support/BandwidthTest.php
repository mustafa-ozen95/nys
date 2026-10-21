<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Format\Bandwidth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BandwidthTest extends TestCase
{
    #[DataProvider('values')]
    public function test_it_formats_bits_per_second(?int $bps, string $expected): void
    {
        $this->assertSame($expected, Bandwidth::format($bps));
    }

    /**
     * @return array<string, array{?int, string}>
     */
    public static function values(): array
    {
        return [
            'sifir' => [0, '0 bps'],
            'birim altinda' => [999, '999 bps'],
            'birim esiginde' => [1000, '1,00 Kbps'],
            'megabit' => [1_500_000, '1,50 Mbps'],
            'gigabit' => [1_000_000_000, '1,00 Gbps'],
            'terabit' => [2_500_000_000_000, '2,50 Tbps'],
            'null' => [null, '—'],
            'negatif' => [-5, '—'],
        ];
    }

    public function test_it_calculates_utilisation_against_link_capacity(): void
    {
        $this->assertSame(50.0, Bandwidth::utilisation(500_000_000, 1000));
        $this->assertSame(0.0, Bandwidth::utilisation(0, 1000));
    }

    public function test_utilisation_is_capped_at_one_hundred(): void
    {
        $this->assertSame(100.0, Bandwidth::utilisation(2_000_000_000, 1000));
    }

    public function test_utilisation_is_null_without_a_known_capacity(): void
    {
        $this->assertNull(Bandwidth::utilisation(1000, null));
        $this->assertNull(Bandwidth::utilisation(1000, 0));
        $this->assertNull(Bandwidth::utilisation(null, 1000));
    }

    public function test_it_picks_a_shared_scale_for_a_series(): void
    {
        $this->assertSame(['divisor' => 1, 'unit' => 'bps'], Bandwidth::scaleFor(940));
        $this->assertSame(['divisor' => 1000, 'unit' => 'Kbps'], Bandwidth::scaleFor(12_500));
        $this->assertSame(['divisor' => 1_000_000, 'unit' => 'Mbps'], Bandwidth::scaleFor(87_860_000));
        $this->assertSame(['divisor' => 1_000_000_000, 'unit' => 'Gbps'], Bandwidth::scaleFor(4_820_000_000));
    }

    public function test_an_empty_series_falls_back_to_the_base_unit(): void
    {
        $this->assertSame(['divisor' => 1, 'unit' => 'bps'], Bandwidth::scaleFor(null));
        $this->assertSame(['divisor' => 1, 'unit' => 'bps'], Bandwidth::scaleFor(0));
    }
}
