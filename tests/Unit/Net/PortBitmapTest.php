<?php

declare(strict_types=1);

namespace Tests\Unit\Net;

use App\Support\Net\PortBitmap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PortBitmapTest extends TestCase
{
    #[DataProvider('bitmaps')]
    public function test_it_decodes_a_port_list(string $hex, array $expected): void
    {
        $this->assertSame($expected, PortBitmap::ports(PortBitmap::fromHex($hex)));
    }

    /**
     * @return array<string, array{string, array<int, int>}>
     */
    public static function bitmaps(): array
    {
        return [
            'ilk oktetin en anlamli biti port 1' => ['80', [1]],
            'ilk oktetin en az anlamli biti port 8' => ['01', [8]],
            'tek oktet dolu' => ['ff', [1, 2, 3, 4, 5, 6, 7, 8]],
            'ikinci oktete tasan port' => ['00 80', [9]],
            'iki ayri oktette birer port' => ['40 02', [2, 15]],
            'bos harita' => ['00 00 00', []],
            '48 portluk switch ilk ve son port' => ['80 00 00 00 00 01', [1, 48]],
        ];
    }

    public function test_it_ignores_separators_and_prefixes_in_hex(): void
    {
        $this->assertSame([1], PortBitmap::ports(PortBitmap::fromHex('0x80')));
        $this->assertSame([1], PortBitmap::ports(PortBitmap::fromHex('80')));
        $this->assertSame([2, 15], PortBitmap::ports(PortBitmap::fromHex('40:02')));
    }

    public function test_malformed_hex_yields_no_ports(): void
    {
        $this->assertSame('', PortBitmap::fromHex('zz'));
        $this->assertSame('', PortBitmap::fromHex('8'));
        $this->assertSame([], PortBitmap::ports(''));
    }

    public function test_encoding_and_decoding_round_trip(): void
    {
        $ports = [1, 2, 9, 24, 48];

        $this->assertSame($ports, PortBitmap::ports(PortBitmap::toOctets($ports)));
    }

    public function test_raw_binary_is_accepted_directly(): void
    {
        $this->assertSame([1, 8], PortBitmap::ports("\x81"));
    }
}
