<?php

declare(strict_types=1);

namespace Tests\Feature\Server;

use App\Services\Server\Driver\WindowsDriver;
use App\Services\Server\RotationError;
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
final class WindowsAccountNameTest extends TestCase
{
    private function miss(string $name): string
    {
        $class = new ReflectionClass(WindowsDriver::class);
        $method = $class->getMethod('escapeName');
        $method->setAccessible(true);

        return $method->invoke($class->newInstanceWithoutConstructor(), $name);
    }

    public function test_unicode_hesap_adi_gecer(): void
    {
        /* Türkçe karakterli hesap adı Windows'ta geçerlidir. */
        $this->assertSame('YÖNETİM', $this->miss('YÖNETİM'));
        $this->assertSame('administrator', $this->miss('administrator'));
        $this->assertSame('ad soyad', $this->miss('ad soyad'));
    }

    public function test_tek_tirnak_ikilenir(): void
    {
        /*
         * Ad tek tırnaklı PowerShell dizgesine giriyor; oradaki tek kaçış
         * kuralı tırnağı İKİLEMEKTİR. Kaçırılmasaydı dizge erken kapanır ve
         * adın kalanı komut olarak okunurdu.
         */
        $this->assertSame("o''neill", $this->miss("o'neill"));
    }

    #[DataProvider('invalidNames')]
    public function test_windowsun_yasakladiklari_reddedilir(string $name): void
    {
        $this->expectException(RotationError::class);

        $this->miss($name);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidNames(): array
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
