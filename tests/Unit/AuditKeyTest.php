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
class AuditKeyTest extends TestCase
{
    private const NAME = 'ACTIVITY_CHAIN_KEY';

    /** @return array<string, mixed> */
    private function settingsWith(?string $value): array
    {
        $old = [$_ENV[self::NAME] ?? null, $_SERVER[self::NAME] ?? null];
        if ($value === null) {
            unset($_ENV[self::NAME], $_SERVER[self::NAME]);
        } else {
            $_ENV[self::NAME] = $_SERVER[self::NAME] = $value;
        }
        try {
            return require config_path('activity.php');
        } finally {
            foreach ([0 => '_ENV', 1 => '_SERVER'] as $i => $array) {
                if ($old[$i] === null) {
                    unset($GLOBALS[$array][self::NAME]);
                } else {
                    $GLOBALS[$array][self::NAME] = $old[$i];
                }
            }
        }
    }

    public function test_bos_anahtar_app_keye_duser(): void
    {
        $this->assertNotSame('', (string) env('APP_KEY'));
        $this->assertSame(env('APP_KEY'), $this->settingsWith('')['chain_key']);
        $this->assertSame(env('APP_KEY'), $this->settingsWith(null)['chain_key']);
    }

    public function test_verilen_anahtar_kullanilir(): void
    {
        $this->assertSame('ayri-anahtar', $this->settingsWith('ayri-anahtar')['chain_key']);
    }
}
