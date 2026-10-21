<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Net\Cidr;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CidrTest extends TestCase
{
    public function test_it_parses_a_block_and_normalises_the_network_address(): void
    {
        $cidr = Cidr::parse('198.51.100.77/24');

        $this->assertSame('198.51.100.0', $cidr->network);
        $this->assertSame(24, $cidr->prefixLength);
        $this->assertSame('198.51.100.0/24', $cidr->toString());
        $this->assertFalse($cidr->isIpv6);
    }

    public function test_it_calculates_netmask_and_boundaries(): void
    {
        $cidr = Cidr::parse('192.168.1.0/24');

        $this->assertSame('255.255.255.0', $cidr->netmask());
        $this->assertSame('192.168.1.255', $cidr->lastAddress());
        $this->assertSame('192.168.1.1', $cidr->firstUsable());
        $this->assertSame('192.168.1.254', $cidr->lastUsable());
        $this->assertSame(254, $cidr->usableCount());
    }

    public function test_point_to_point_block_has_two_usable_addresses(): void
    {
        $cidr = Cidr::parse('10.0.0.0/31');

        $this->assertTrue($cidr->isPointToPoint());
        $this->assertSame('10.0.0.0', $cidr->firstUsable());
        $this->assertSame('10.0.0.1', $cidr->lastUsable());
        $this->assertSame(2, $cidr->usableCount());
    }

    public function test_single_host_block_has_one_usable_address(): void
    {
        $cidr = Cidr::parse('10.0.0.7/32');

        $this->assertTrue($cidr->isSingleHost());
        $this->assertSame('10.0.0.7', $cidr->firstUsable());
        $this->assertSame('10.0.0.7', $cidr->lastUsable());
        $this->assertSame(1, $cidr->usableCount());
    }

    /**
     * Aralık SAYI değil PAKETLENMİŞ BAYT'tır: 128 bit adres tamsayıya sığmaz
     * ve aynı kodun iki aile için de çalışması gerekir. Baytlar sözlük
     * sırasında karşılaştırıldığında ağ sırasıyla aynı sonucu verir.
     */
    public function test_it_exposes_a_binary_range_for_ipv4(): void
    {
        $cidr = Cidr::parse('10.0.0.0/30');

        $this->assertSame(inet_pton('10.0.0.0'), $cidr->addressStart());
        $this->assertSame(inet_pton('10.0.0.3'), $cidr->addressEnd());
        $this->assertTrue($cidr->addressStart() < $cidr->addressEnd());
    }

    public function test_it_exposes_a_binary_range_for_ipv6(): void
    {
        $cidr = Cidr::parse('2001:db8::/126');

        $this->assertSame(inet_pton('2001:db8::'), $cidr->addressStart());
        $this->assertSame(inet_pton('2001:db8::3'), $cidr->addressEnd());
    }

    /**
     * IPv6'da yayın adresi yoktur; yalnızca ağ adresi (alt ağ yönlendirici
     * anycast, RFC 4291) ayrılır.
     */
    public function test_ipv6_usable_range_skips_only_the_network_address(): void
    {
        $cidr = Cidr::parse('2001:db8::/126');

        $this->assertSame('2001:db8::1', $cidr->firstUsable());
        $this->assertSame('2001:db8::3', $cidr->lastUsable());
        $this->assertSame(3, $cidr->usableCount());
    }

    /**
     * Bir /64'te 2^64 adres vardır: sayılamaz. Sıfır demek yanlış olurdu,
     * blok boş değil.
     */
    public function test_huge_ipv6_blocks_have_no_countable_size(): void
    {
        $this->assertNull(Cidr::parse('2001:db8::/32')->usableCount());
    }

    public function test_ipv6_blocks_can_be_split(): void
    {
        $subnets = Cidr::parse('2001:db8::/48')->splitInto(52);

        $this->assertCount(16, $subnets);
        $this->assertSame('2001:db8::/52', $subnets[0]->toString());
        $this->assertSame('2001:db8:0:f000::/52', $subnets[15]->toString());
    }

    /**
     * Sınırsız bölme istemek bir hatadır: bir /32'yi /64'lere bölmek 2^32
     * alt ağ eder ve bellek tükenene kadar çalışırdı.
     */
    public function test_absurd_splits_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cidr::parse('2001:db8::/32')->splitInto(64);
    }

    public function test_it_answers_whether_an_address_is_inside(): void
    {
        $cidr = Cidr::parse('2001:db8::/32');

        $this->assertTrue($cidr->containsAddress('2001:db8:1::5'));
        $this->assertFalse($cidr->containsAddress('2001:db9::1'));

        // Aile farkı: IPv4 adres IPv6 bloğun içinde sayılmaz.
        $this->assertFalse($cidr->containsAddress('10.0.0.1'));
    }

    public function test_containment_is_directional(): void
    {
        $parent = Cidr::parse('10.0.0.0/16');

        $this->assertTrue($parent->contains('10.0.5.0/24'));
        $this->assertTrue($parent->contains($parent));
        $this->assertFalse($parent->contains('10.1.0.0/24'));
        $this->assertFalse(Cidr::parse('10.0.5.0/24')->contains($parent));
    }

    public function test_overlap_is_symmetric(): void
    {
        $a = Cidr::parse('10.0.0.0/16');
        $b = Cidr::parse('10.0.5.0/24');
        $c = Cidr::parse('10.1.0.0/24');

        $this->assertTrue($a->overlaps($b));
        $this->assertTrue($b->overlaps($a));
        $this->assertFalse($a->overlaps($c));
        $this->assertFalse($c->overlaps($a));
    }

    public function test_it_splits_a_block_into_equal_subnets(): void
    {
        $subnets = Cidr::parse('10.0.0.0/24')->splitInto(26);

        $this->assertCount(4, $subnets);
        $this->assertSame(
            ['10.0.0.0/26', '10.0.0.64/26', '10.0.0.128/26', '10.0.0.192/26'],
            array_map(fn (Cidr $cidr): string => $cidr->toString(), $subnets),
        );
    }

    public function test_splitting_into_the_maximum_prefix_yields_every_address(): void
    {
        $this->assertCount(4, Cidr::parse('10.0.0.0/30')->splitInto(32));
    }

    public function test_it_rejects_a_subnet_prefix_that_is_not_smaller_than_the_block(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cidr::parse('10.0.0.0/24')->splitInto(24);
    }

    public function test_it_handles_ipv6_blocks(): void
    {
        $cidr = Cidr::parse('2001:db8::1/32');

        $this->assertTrue($cidr->isIpv6);
        $this->assertSame('2001:db8::/32', $cidr->toString());
        $this->assertNull($cidr->netmask(), 'IPv6’da noktalı maske yoktur.');
        $this->assertTrue($cidr->contains('2001:db8:1::/48'));
        $this->assertFalse($cidr->contains('2001:db9::/48'));
    }

    public function test_ipv4_and_ipv6_blocks_never_contain_each_other(): void
    {
        $this->assertFalse(Cidr::parse('0.0.0.0/0')->contains('2001:db8::/32'));
    }

    #[DataProvider('invalidValues')]
    public function test_it_rejects_invalid_input(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cidr::parse($value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidValues(): array
    {
        return [
            'prefix yok' => ['10.0.0.0'],
            'prefix bos' => ['10.0.0.0/'],
            'prefix sayi degil' => ['10.0.0.0/abc'],
            'prefix cok buyuk' => ['10.0.0.0/33'],
            'gecersiz adres' => ['10.0.0.256/24'],
            'bos deger' => [''],
        ];
    }

    public function test_try_parse_returns_null_instead_of_throwing(): void
    {
        $this->assertNull(Cidr::tryParse('bozuk'));
        $this->assertInstanceOf(Cidr::class, Cidr::tryParse('10.0.0.0/8'));
    }
}
