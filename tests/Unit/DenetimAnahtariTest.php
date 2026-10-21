<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Denetim zincirinin anahtarı: boş verilen değer, verilmemiş sayılır.
 *
 * Compose değişkeni listeden geçirir; .env'de boş kalan satır konteynere boş
 * dize olarak gelir. Boş anahtarla imzalanan bir zincir, imzasız zincirdir.
 */
class DenetimAnahtariTest extends TestCase
{
    private const AD = 'ACTIVITY_CHAIN_KEY';

    /** @return array<string, mixed> */
    private function ayarlarIle(?string $deger): array
    {
        $eski = [$_ENV[self::AD] ?? null, $_SERVER[self::AD] ?? null];
        if ($deger === null) {
            unset($_ENV[self::AD], $_SERVER[self::AD]);
        } else {
            $_ENV[self::AD] = $_SERVER[self::AD] = $deger;
        }
        try {
            return require config_path('activity.php');
        } finally {
            foreach ([0 => '_ENV', 1 => '_SERVER'] as $i => $dizi) {
                if ($eski[$i] === null) {
                    unset($GLOBALS[$dizi][self::AD]);
                } else {
                    $GLOBALS[$dizi][self::AD] = $eski[$i];
                }
            }
        }
    }

    public function test_bos_anahtar_app_keye_duser(): void
    {
        $this->assertNotSame('', (string) env('APP_KEY'));
        $this->assertSame(env('APP_KEY'), $this->ayarlarIle('')['chain_key']);
        $this->assertSame(env('APP_KEY'), $this->ayarlarIle(null)['chain_key']);
    }

    public function test_verilen_anahtar_kullanilir(): void
    {
        $this->assertSame('ayri-anahtar', $this->ayarlarIle('ayri-anahtar')['chain_key']);
    }
}
