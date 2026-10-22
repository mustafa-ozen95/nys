<?php

declare(strict_types=1);

namespace Tests\Feature\Seeding;

use Database\Seeders\LocalInventory;
use RuntimeException;
use Tests\TestCase;

/**
 * VERİ DEPODA DURMAZ.
 *
 * Envanter `database/seeders/local/inventory.php` içindedir ve o dosya
 * .gitignore'dadır. Buradaki iki kural, dosyanın yokluğunun iki farklı yerde
 * iki farklı şey ifade etmesinden doğuyor:
 *
 *  - ÜRETİMDE eksik dosya bir HATADIR: kurulumu yapan kişi onu doldurmayı
 *    unutmuştur ve bunu ekranda görmelidir.
 *  - Üretim dışında eksik dosya NORMALDİR: depoyu klonlayan birinde zaten
 *    olmaz. Seeder sessizce hiçbir şey yazmaz — uydurma kayıt üretmek, yeni
 *    bir kuruluma ait olmayan sunucular bırakmak olurdu.
 */
final class LocalInventoryTest extends TestCase
{
    private string $directory;

    /*
     * Kaynak GEÇİCİ BİR DİZİNE bağlanır.
     *
     * Depodaki gerçek dosyaya bakılsaydı test, koştuğu makinede o dosyanın
     * olup olmamasına göre farklı davranırdı — yani bazen hiç koşmazdı.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/nys-envanter-'.bin2hex(random_bytes(6));
        mkdir($this->directory);

        LocalInventory::directoryConfigure($this->directory);
    }

    protected function tearDown(): void
    {
        LocalInventory::directoryConfigure(null);

        array_map(unlink(...), glob($this->directory.'/*') ?: []);
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_uretimde_dosya_yoksa_durur(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/inventory\.php/i');

        LocalInventory::take('switchler');
    }

    public function test_uretim_disinda_dosya_yoksa_bos_doner(): void
    {
        $this->assertSame([], LocalInventory::take('switchler'));
        $this->assertSame([], LocalInventory::take('sunucular'));
    }

    public function test_dosya_varsa_okunur(): void
    {
        file_put_contents(
            $this->directory.'/inventory.php',
            "<?php return ['switchler' => [['hostname' => 'sw1']]];",
        );

        LocalInventory::directoryConfigure($this->directory);

        $this->assertSame([['hostname' => 'sw1']], LocalInventory::take('switchler'));
    }
}
