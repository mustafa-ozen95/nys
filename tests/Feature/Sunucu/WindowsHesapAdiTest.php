<?php

declare(strict_types=1);

namespace Tests\Feature\Sunucu;

use App\Services\Sunucu\DondurmeHatasi;
use App\Services\Sunucu\Surucu\WindowsSurucusu;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Windows hesap adının PowerShell'e gömülmesi.
 *
 * Windows kullanıcı adları Unicode olabilir (ör. makine `PC-01` üzerinde
 * `YÖNETİM` hesabı). Dar bir süzgeç (`[A-Za-z0-9._-]`) böyle bir adı reddeder
 * ve tur makineye HİÇ BAĞLANMADAN "hesap adı geçersiz" diyerek düşer — oysa
 * adı biz seçmedik, kasadaki hesap neyse odur.
 *
 * Sınanan iki şey: var olan adların geçmesi ve adın gömüldüğü yerde
 * kaçırılması.
 */
final class WindowsHesapAdiTest extends TestCase
{
    private function kacir(string $ad): string
    {
        $sinif = new ReflectionClass(WindowsSurucusu::class);
        $yontem = $sinif->getMethod('adiKacir');
        $yontem->setAccessible(true);

        return $yontem->invoke($sinif->newInstanceWithoutConstructor(), $ad);
    }

    public function test_unicode_hesap_adi_gecer(): void
    {
        /* Türkçe karakterli hesap adı Windows'ta geçerlidir. */
        $this->assertSame('YÖNETİM', $this->kacir('YÖNETİM'));
        $this->assertSame('administrator', $this->kacir('administrator'));
        $this->assertSame('ad soyad', $this->kacir('ad soyad'));
    }

    public function test_tek_tirnak_ikilenir(): void
    {
        /*
         * Ad tek tırnaklı PowerShell dizgesine giriyor; oradaki tek kaçış
         * kuralı tırnağı İKİLEMEKTİR. Kaçırılmasaydı dizge erken kapanır ve
         * adın kalanı komut olarak okunurdu.
         */
        $this->assertSame("o''neill", $this->kacir("o'neill"));
    }

    #[DataProvider('gecersizAdlar')]
    public function test_windowsun_yasakladiklari_reddedilir(string $ad): void
    {
        $this->expectException(DondurmeHatasi::class);

        $this->kacir($ad);
    }

    /** @return array<string, array{0: string}> */
    public static function gecersizAdlar(): array
    {
        return [
            'bos' => [''],
            'cift tirnak' => ['ko"tu'],
            'ters bolu' => ['alan\\kullanici'],
            'koseli' => ['ad[1]'],
            'iki nokta' => ['ad:1'],
            'yildiz' => ['ad*'],
            'yirmi karakterden uzun' => ['aaaaaaaaaaaaaaaaaaaaa'],
            'denetim karakteri' => ["ad\nsatir"],
        ];
    }
}
