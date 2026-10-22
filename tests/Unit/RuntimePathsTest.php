<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Çalışma yollarının İngilizce adlara geçişi (dc-paths): eski hacimdeki adlar
 * açılışta taşınır, ikisi birden varsa kimseye dokunulmaz.
 */
class RuntimePathsTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../docker/rootfs/usr/local/bin/dc-paths';

    private const PAIRS = [
        'veritabani=database', 'database/baglanti.json=database/connection.json', 'database/durum.json=database/state.json',
        'guncelleme=update', 'update/durum.json=update/state.json', 'ilk-parola.txt=initial-password.txt',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/paths-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function runPaths(): array
    {
        $cmd = array_merge(['sh', self::SCRIPT, $this->root], self::PAIRS);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        $code = proc_close($p);

        return [$code, $output];
    }

    public function test_eski_adlar_yeni_adlara_tasinir_icerik_korunur(): void
    {
        mkdir($this->root.'/veritabani');
        file_put_contents($this->root.'/veritabani/baglanti.json', 'SIFRELI');
        file_put_contents($this->root.'/veritabani/durum.json', '{"durum":"x"}');
        mkdir($this->root.'/guncelleme');
        file_put_contents($this->root.'/guncelleme/durum.json', '{"durum":"guncel"}');
        file_put_contents($this->root.'/ilk-parola.txt', "ad\nparola\n");

        [$code] = $this->runPaths();

        $this->assertSame(0, $code);
        $this->assertSame('SIFRELI', file_get_contents($this->root.'/database/connection.json'));
        $this->assertSame('{"durum":"x"}', file_get_contents($this->root.'/database/state.json'));
        $this->assertSame('{"durum":"guncel"}', file_get_contents($this->root.'/update/state.json'));
        $this->assertSame("ad\nparola\n", file_get_contents($this->root.'/initial-password.txt'));
        $this->assertFileDoesNotExist($this->root.'/veritabani');
        $this->assertFileDoesNotExist($this->root.'/guncelleme');
        $this->assertFileDoesNotExist($this->root.'/ilk-parola.txt');
    }

    public function test_ikisi_de_varsa_dokunulmaz_uyari_yazilir(): void
    {
        mkdir($this->root.'/veritabani');
        file_put_contents($this->root.'/veritabani/baglanti.json', 'ESKI');
        mkdir($this->root.'/database');
        file_put_contents($this->root.'/database/connection.json', 'YENI');

        [$code, $output] = $this->runPaths();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('UYARI', $output);
        $this->assertSame('ESKI', file_get_contents($this->root.'/veritabani/baglanti.json'));
        $this->assertSame('YENI', file_get_contents($this->root.'/database/connection.json'));
    }

    public function test_hicbiri_yoksa_sessizce_gecer(): void
    {
        [$code, $output] = $this->runPaths();

        $this->assertSame(0, $code);
        $this->assertSame('', $output);
        $this->assertSame([], array_values(array_diff(scandir($this->root), ['.', '..'])));
    }

    public function test_ikinci_calistirma_bir_sey_degistirmez(): void
    {
        mkdir($this->root.'/veritabani');
        file_put_contents($this->root.'/veritabani/durum.json', 'X');

        $this->runPaths();
        [$code, $output] = $this->runPaths();

        $this->assertSame(0, $code);
        $this->assertSame('', $output);
        $this->assertSame('X', file_get_contents($this->root.'/database/state.json'));
    }
}
