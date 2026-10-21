<?php

declare(strict_types=1);

namespace Tests\Feature\Seeding;

use Database\Seeders\YerelEnvanter;
use RuntimeException;
use Tests\TestCase;

/**
 * VERİ DEPODA DURMAZ.
 *
 * Envanter `database/seeders/yerel/envanter.php` içindedir ve o dosya
 * .gitignore'dadır. Buradaki iki kural, dosyanın yokluğunun iki farklı yerde
 * iki farklı şey ifade etmesinden doğuyor:
 *
 *  - ÜRETİMDE eksik dosya bir HATADIR: kurulumu yapan kişi onu doldurmayı
 *    unutmuştur ve bunu ekranda görmelidir.
 *  - Üretim dışında eksik dosya NORMALDİR: depoyu klonlayan birinde zaten
 *    olmaz. Seeder sessizce hiçbir şey yazmaz — uydurma kayıt üretmek, yeni
 *    bir kuruluma ait olmayan sunucular bırakmak olurdu.
 */
final class YerelEnvanterTest extends TestCase
{
    private string $dizin;

    /*
     * Kaynak GEÇİCİ BİR DİZİNE bağlanır.
     *
     * Depodaki gerçek dosyaya bakılsaydı test, koştuğu makinede o dosyanın
     * olup olmamasına göre farklı davranırdı — yani bazen hiç koşmazdı.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dizin = sys_get_temp_dir().'/nys-envanter-'.bin2hex(random_bytes(6));
        mkdir($this->dizin);

        YerelEnvanter::dizinAyarla($this->dizin);
    }

    protected function tearDown(): void
    {
        YerelEnvanter::dizinAyarla(null);

        array_map(unlink(...), glob($this->dizin.'/*') ?: []);
        @rmdir($this->dizin);

        parent::tearDown();
    }

    public function test_uretimde_dosya_yoksa_durur(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/envanter\.php/i');

        YerelEnvanter::al('switchler');
    }

    public function test_uretim_disinda_dosya_yoksa_bos_doner(): void
    {
        $this->assertSame([], YerelEnvanter::al('switchler'));
        $this->assertSame([], YerelEnvanter::al('sunucular'));
    }

    public function test_dosya_varsa_okunur(): void
    {
        file_put_contents(
            $this->dizin.'/envanter.php',
            "<?php return ['switchler' => [['hostname' => 'sw1']]];",
        );

        YerelEnvanter::dizinAyarla($this->dizin);

        $this->assertSame([['hostname' => 'sw1']], YerelEnvanter::al('switchler'));
    }
}
