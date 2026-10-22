<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Database\ConnectionFile;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Panelin veritabanı ayar dosyaları.
 *
 * Bu dosyayı başlatıcı uygulamayı ayağa kaldırmadan okuyor; bu yüzden test de
 * Laravel'siz: düz Encrypter ve geçici bir dizin.
 */
class ConnectionFileTest extends TestCase
{
    private string $directory;

    private const CONNECTION = [
        'host' => 'db.ornek.local',
        'port' => 3307,
        'database' => 'panel',
        'username' => 'panel_uzak',
        'password' => "p@ss \"w0rd\" #x \\ 'q'\tson",
    ];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/baglanti-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->directory);
    }

    private function file(?string $key = null): ConnectionFile
    {
        return new ConnectionFile(new Encrypter($key ?? str_repeat('a', 32), 'AES-256-CBC'), $this->directory);
    }

    public function test_baglanti_sifreli_yazilir_ve_geri_okunur(): void
    {
        $this->file()->connectionWrite(self::CONNECTION);

        $this->assertSame(self::CONNECTION, $this->file()->connection());

        $raw = (string) file_get_contents($this->directory.'/connection.json');
        $this->assertStringNotContainsString('w0rd', $raw);
        $this->assertStringNotContainsString('panel_uzak', $raw);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->directory.'/connection.json')), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->directory)), -4));
    }

    public function test_baska_anahtarla_okunamaz(): void
    {
        $this->file()->connectionWrite(self::CONNECTION);

        $this->expectException(RuntimeException::class);
        $this->file(str_repeat('b', 32))->connection();
    }

    public function test_dosya_yoksa_baglanti_yok(): void
    {
        $this->assertNull($this->file()->connection());
        $this->assertNull($this->file()->status());
    }

    /** Başarısızlıkta parola diskte kalmaz: bir daha okunmayacak, ancak sızabilir. */
    public function test_basarisiz_asamasi_baglantiyi_siler(): void
    {
        $file = $this->file();
        $file->connectionWrite(self::CONNECTION);
        $file->stageWrite('tasinacak', null, ['hedef' => 'panel_uzak@db.ornek.local:3307/panel', 'isteyen' => 'Yönetici']);
        $file->stageWrite('basarisiz', 'Uzak veritabanı boş değil.');

        $this->assertFileDoesNotExist($this->directory.'/connection.json');
        $this->assertSame('basarisiz', $file->stage());
        $this->assertSame('Uzak veritabanı boş değil.', $file->status()['aciklama']);
        $this->assertSame('Yönetici', $file->status()['isteyen'], 'Önceki alanlar korunmalı.');
    }

    public function test_yeni_istek_onceki_sonucu_tasimaz(): void
    {
        $file = $this->file();
        $file->stageWrite('basarisiz', 'eski sebep', ['hedef' => 'eski']);
        $file->stageWrite('tasinacak', null, ['hedef' => 'yeni']);

        $this->assertArrayNotHasKey('aciklama', $file->status());
        $this->assertSame('yeni', $file->status()['hedef']);
    }

    public function test_aciklamasiz_ilerleme_onceki_aciklamayi_korur(): void
    {
        $file = $this->file();
        $file->stageWrite('tasinacak');
        $file->stageWrite('deneniyor', '42 tablo, 900 satır kopyalandı; iki tarafta sayılar aynı.');
        $file->stageWrite('etkin');

        $this->assertSame('etkin', $file->stage());
        $this->assertSame('42 tablo, 900 satır kopyalandı; iki tarafta sayılar aynı.', $file->status()['aciklama']);
    }

    public function test_bilinmeyen_asama_yazilmaz(): void
    {
        $this->expectException(RuntimeException::class);
        $this->file()->stageWrite('bitti');
    }

    public function test_bozuk_durum_dosyasi_yok_sayilir(): void
    {
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/state.json', '{"durum":"uydurma"}');

        $this->assertNull($this->file()->status());
        $this->assertNull($this->file()->stage());
    }

    /** Başlatıcı satırları base64'ten çözüp ortama alıyor; tırnaklı parola bozulmamalı. */
    public function test_ortam_satirlari_degeri_bozmadan_tasir(): void
    {
        $this->file()->connectionWrite(self::CONNECTION);

        $rows = [];
        foreach (array_filter(explode("\n", $this->file()->environmentRows())) as $row) {
            [$name, $value] = explode('=', $row, 2);
            $rows[$name] = base64_decode($value, true);
        }

        $this->assertSame([
            'DB_HOST' => 'db.ornek.local',
            'DB_PORT' => '3307',
            'DB_DATABASE' => 'panel',
            'DB_USERNAME' => 'panel_uzak',
            'DB_PASSWORD' => self::CONNECTION['password'],
        ], $rows);
    }

    public function test_istemci_ayari_parolayi_tirnaklar(): void
    {
        $this->file()->connectionWrite(self::CONNECTION);

        $this->assertSame(
            "[client]\nhost=\"db.ornek.local\"\nport=3307\nuser=\"panel_uzak\"\npassword=\"p@ss \\\"w0rd\\\" #x \\\\ 'q'\\tson\"\n",
            $this->file()->clientSetting(),
        );
    }

    public function test_yeniden_baslatma_istegi_dosya_birakir(): void
    {
        $this->assertTrue($this->file()->reStartRequest());
        $this->assertFileExists($this->directory.'/restart');
    }

    public function test_temizle_yalnizca_bilinen_alanlari_alir(): void
    {
        $this->assertSame(
            ['host' => 'db', 'port' => 3306, 'database' => 'panel', 'username' => 'u', 'password' => ' p '],
            ConnectionFile::clean(['host' => ' db ', 'port' => '', 'database' => 'panel', 'username' => 'u', 'password' => ' p ', 'fazla' => 'x']),
        );
    }
}
