<?php

declare(strict_types=1);

namespace Tests\Unit\Net;

use App\Support\Net\Cidr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Aralığın CIDR'lara bölünmesi.
 *
 * NEDEN ÖNEMLİ: bir bloğun içindeki BOŞLUK düzgün bir CIDR'a denk gelmez.
 * Kullanıcı o boşluğa bir alt ağ açacak ve hangi maskelerin sığdığını
 * görmesi gerekiyor; "10.0.0.8-29" demek ona bunu söylemez.
 *
 * Beklenen sonuçlar elle doğrulandı: her parça kendi sınırına HİZALI olmalı
 * (bir /29 ancak 8'in katında başlayabilir) ve parçalar aralığı tam
 * kapatmalı — ne eksik ne taşan.
 */
final class CidrRangeTest extends TestCase
{
    /**
     * @param  list<string>  $beklenen
     */
    #[DataProvider('araliklar')]
    public function test_araligi_en_az_parcaya_boler(string $bas, string $son, array $beklenen, bool $v6 = false): void
    {
        $parcalar = array_map(
            static fn (Cidr $c): string => $c->toString(),
            Cidr::fromRange(inet_pton($bas), inet_pton($son), $v6),
        );

        $this->assertSame($beklenen, $parcalar);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3?: bool}>
     */
    public static function araliklar(): array
    {
        return [
            'tam blok tek parca' => ['10.0.0.0', '10.0.0.255', ['10.0.0.0/24']],
            'hizali tek alt ag' => ['10.0.0.8', '10.0.0.15', ['10.0.0.8/29']],
            'tek adres' => ['10.0.0.1', '10.0.0.1', ['10.0.0.1/32']],

            /* Hizasız uçlar: baştan ve sondan kırpılır. */
            'hizasiz aralik' => ['10.0.0.8', '10.0.0.29', [
                '10.0.0.8/29', '10.0.0.16/29', '10.0.0.24/30', '10.0.0.28/31',
            ]],
            'bastaki alt ag ayrildiktan sonra kalan' => ['10.0.0.16', '10.0.0.255', [
                '10.0.0.16/28', '10.0.0.32/27', '10.0.0.64/26', '10.0.0.128/25',
            ]],
            'iki ucu da hizasiz' => ['192.168.1.5', '192.168.1.10', [
                '192.168.1.5/32', '192.168.1.6/31', '192.168.1.8/31', '192.168.1.10/32',
            ]],

            'ipv6' => ['2001:db8::8', '2001:db8::1f', ['2001:db8::8/125', '2001:db8::10/124'], true],
        ];
    }

    /** Ters aralık BOŞ döner; çağıran yerlerde "boşluk yok" demektir. */
    public function test_ters_aralik_bos_doner(): void
    {
        $this->assertSame([], Cidr::fromRange(inet_pton('10.0.0.29'), inet_pton('10.0.0.8'), false));
    }

    /** Aile karışırsa boş döner: 4 baytla 16 baytı karşılaştırmak anlamsız. */
    public function test_aile_karisirsa_bos_doner(): void
    {
        $this->assertSame([], Cidr::fromRange(inet_pton('10.0.0.0'), inet_pton('2001:db8::1'), false));
    }

    /**
     * Parçalar aralığı TAM kapatmalı: son parçanın son adresi aralığın
     * sonuna eşit olmalı, arada delik kalmamalı.
     */
    public function test_parcalar_araligi_tam_kapatir(): void
    {
        $bas = inet_pton('10.0.0.3');
        $son = inet_pton('10.0.1.200');

        $parcalar = Cidr::fromRange($bas, $son, false);

        $this->assertNotSame([], $parcalar);
        $this->assertSame($bas, inet_pton($parcalar[0]->network));
        $this->assertSame($son, inet_pton($parcalar[count($parcalar) - 1]->lastAddress()));

        /* Parçalar birbirine bitişik: her biri bir öncekinin bittiği yerde başlar. */
        for ($i = 1; $i < count($parcalar); $i++) {
            $this->assertSame(
                Cidr::increment(inet_pton($parcalar[$i - 1]->lastAddress())),
                inet_pton($parcalar[$i]->network),
            );
        }
    }
}
