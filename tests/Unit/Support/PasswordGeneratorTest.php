<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Credentials\PasswordGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Cihaz parolası üreteci.
 *
 * Uzunluk ve karakter sınıfları ayarlardan gelir. İki şey her ayarda doğru
 * kalmak zorunda:
 *
 *  1. Üretilen parola AÇIK OLAN HER SINIFTAN en az bir karakter taşır.
 *     Taşımasaydı "rakam açık" demek "rakam olabilir" olurdu ve parolanın
 *     cihaz kuralını geçip geçmeyeceği şansa kalırdı.
 *  2. Cihazın reddedeceği bir birleşim ÜRETİLMEZ. Junos yalnız küçük (ya da
 *     yalnız büyük) harften oluşan parolayı reddediyor; öyle bir parola
 *     commit check'te düşer ve tur ortada kalırdı.
 */
final class PasswordGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classes();
    }

    public function test_istenen_uzunlukta_uretir(): void
    {
        $this->assertSame(24, mb_strlen($this->generate(24)));
        $this->assertSame(32, mb_strlen($this->generate(32)));
    }

    public function test_uzunluk_ayardan_okunur(): void
    {
        config(['credentials.rotation.length' => 40]);

        $this->assertSame(40, mb_strlen($this->generate()));
    }

    public function test_acik_olan_her_siniftan_karakter_tasir(): void
    {
        // 11: üç sınıfın 64 bit tabanı geçtiği en kısa uzunluk.
        for ($i = 0; $i < 100; $i++) {
            $password = $this->generate(11);

            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
        }
    }

    public function test_kapatilan_sinif_hic_kullanilmaz(): void
    {
        $this->classes(uppercase: false, symbols: false);

        $all = '';

        for ($i = 0; $i < 50; $i++) {
            $all .= $this->generate(24);
        }

        $this->assertDoesNotMatchRegularExpression('/[A-Z]/', $all);
        $this->assertMatchesRegularExpression('/[a-z]/', $all);
        $this->assertMatchesRegularExpression('/[0-9]/', $all);
    }

    public function test_noktalama_acilabilir(): void
    {
        $this->classes(symbols: true);

        $all = '';

        for ($i = 0; $i < 50; $i++) {
            $all .= $this->generate(24);
        }

        $this->assertMatchesRegularExpression('/[!#%+,\-.:=?@^_~]/', $all);

        // Kabuk ve URL'de sorun çıkaranlar havuzda YOK.
        foreach (['"', "'", '\\', '$', '`', ' '] as $character) {
            $this->assertStringNotContainsString($character, $all);
        }
    }

    /**
     * Cihaz TEK karakter sınıfından oluşan parolayı reddediyor — yalnız
     * rakam da dahil; "rakam tek başına yeter" varsayımı yanlıştır. Bu
     * jn32-test üzerinde ölçüldü.
     */
    public function test_yalnizca_rakam_reddedilir(): void
    {
        $this->classes(lowercase: false, uppercase: false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/en az iki sınıf/');

        $this->generate(16);
    }

    public function test_yalnizca_noktalama_reddedilir(): void
    {
        $this->classes(lowercase: false, uppercase: false, digits: false, symbols: true);

        $this->expectException(InvalidArgumentException::class);

        $this->generate(16);
    }

    /**
     * ENTROPİ TABANI. Cihaz iki sınıfla sekiz karakteri kabul eder ama
     * "23456789" gibi bir dizi 24 bittir; Junos parolayı özet olarak tutuyor
     * ve yapılandırma yedeği her commit'te cihazdan çıkıyor, yani özet
     * çevrimdışı denenebilir.
     */
    public function test_zayif_kalan_birlesim_reddedilir(): void
    {
        $this->classes(uppercase: false, symbols: false);

        // küçük+rakam havuzu 33 sembol: 8 karakter ~40 bit, taban 64.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/çok zayıf/');

        $this->generate(8);
    }

    public function test_yeterli_uzunlukta_ayni_birlesim_kabul_edilir(): void
    {
        $this->classes(uppercase: false, symbols: false);

        $this->assertSame(13, mb_strlen($this->generate(13)));
    }

    public function test_entropi_hesabi(): void
    {
        // 8 sembollük havuzda 8 karakter = 24 bit.
        $this->assertEqualsWithDelta(24.0, PasswordGenerator::entropyBits(8, 8), 0.01);

        // 57 sembollük havuzda 24 karakter ~140 bit.
        $this->assertGreaterThan(139, PasswordGenerator::entropyBits(24, 57));
    }

    /**
     * Karışan karakterler arıza anında konsoldan elle yazılırken 0/O ayrımını
     * imkânsız kılıyor; ayardan açılıp kapatılabilir.
     */
    public function test_karisan_karakterler_ayardan_yonetilir(): void
    {
        $all = '';

        for ($i = 0; $i < 200; $i++) {
            $all .= $this->generate(24);
        }

        foreach (['0', 'O', '1', 'l', 'I'] as $character) {
            $this->assertStringNotContainsString($character, $all);
        }

        // Kapatılınca havuz genişler.
        $this->classes(avoidAmbiguous: false);

        $wide = '';

        for ($i = 0; $i < 200; $i++) {
            $wide .= $this->generate(24);
        }

        $this->assertMatchesRegularExpression('/[01OlI]/', $wide);
    }

    public function test_ayni_parolayi_iki_kez_uretmez(): void
    {
        $passwords = [];

        for ($i = 0; $i < 200; $i++) {
            $passwords[] = $this->generate(24);
        }

        $this->assertCount(200, array_unique($passwords));
    }

    public function test_cok_kisa_uzunluk_reddedilir(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->generate(7);
    }

    /**
     * Cihaz yalnız küçük harften oluşan parolayı reddediyor; böyle bir ayar
     * kabul edilmez.
     */
    public function test_cihazin_reddedecegi_birlesim_uretilmez(): void
    {
        $this->classes(uppercase: false, digits: false, symbols: false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reddeder/');

        $this->generate(24);
    }

    /**
     * Cihaz kuralı: EN AZ İKİ farklı sınıf. Hangi ikisi olduğu fark etmez.
     */
    public function test_iki_sinifin_her_birlesimi_kabul_edilir(): void
    {
        $unions = [
            [true, true, false, false],
            [true, false, true, false],
            [false, true, true, false],
            [true, false, false, true],
            [false, false, true, true],
        ];

        foreach ($unions as [$l, $u, $d, $s]) {
            $this->assertTrue(
                PasswordGenerator::satisfiesDevice($l, $u, $d, $s),
                'İki sınıflı birleşim reddedildi: '.json_encode([$l, $u, $d, $s]),
            );
        }
    }

    public function test_yalnizca_buyuk_harf_de_reddedilir(): void
    {
        $this->classes(lowercase: false, digits: false, symbols: false);

        $this->expectException(InvalidArgumentException::class);

        $this->generate(24);
    }

    public function test_hicbir_sinif_acik_degilse_reddedilir(): void
    {
        $this->classes(lowercase: false, uppercase: false, digits: false, symbols: false);

        $this->expectException(InvalidArgumentException::class);

        $this->generate(24);
    }

    /**
     * Tek sınıflı HER parola reddedilir — yalnız rakam ve yalnız noktalama
     * dahil. Bu, cihazda ölçülmüş davranıştır.
     */
    public function test_tek_sinif_her_zaman_reddedilir(): void
    {
        $this->assertFalse(PasswordGenerator::satisfiesDevice(true, false, false, false));
        $this->assertFalse(PasswordGenerator::satisfiesDevice(false, true, false, false));
        $this->assertFalse(PasswordGenerator::satisfiesDevice(false, false, true, false));
        $this->assertFalse(PasswordGenerator::satisfiesDevice(false, false, false, true));
        $this->assertFalse(PasswordGenerator::satisfiesDevice(false, false, false, false));
    }

    private function classes(
        bool $lowercase = true,
        bool $uppercase = true,
        bool $digits = true,
        bool $symbols = false,
        bool $avoidAmbiguous = true,
    ): void {
        config([
            'credentials.rotation.lowercase' => $lowercase,
            'credentials.rotation.uppercase' => $uppercase,
            'credentials.rotation.digits' => $digits,
            'credentials.rotation.symbols' => $symbols,
            'credentials.rotation.avoid_ambiguous' => $avoidAmbiguous,
            'credentials.rotation.length' => 24,
        ]);
    }

    private function generate(?int $length = null): string
    {
        return (new PasswordGenerator)->generate($length);
    }
}
