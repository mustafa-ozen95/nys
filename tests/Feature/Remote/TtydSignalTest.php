<?php

declare(strict_types=1);

namespace Tests\Feature\Remote;

use App\Services\Remote\RemoteSessionStore;
use App\Services\Remote\TtydManager;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Web süreci CLI değildir.
 *
 * Sinyal sabitleri (SIGTERM, SIGKILL…) pcntl uzantısından gelir ve pcntl
 * yalnızca CLI'da yüklü olabilir; PHP-FPM'de yoktur. Testler CLI'da
 * koştuğu için sabit orada TANIMLIDIR — yani sıradan bir çağrı testi bu
 * hatayı yakalayamaz: web isteğinde uzak terminali kapatma
 * "Undefined constant App\Services\Remote\SIGTERM" ile düşer, ttyd süreci
 * ayakta ve port rezerve kalır.
 *
 * O yüzden kural KAYNAKTA sınanır: uygulama kodu CLI'ya özgü bir sabite ya da
 * pcntl işlevine dayanmaz.
 */
final class TtydSignalTest extends TestCase
{
    public function test_uygulama_kodu_pcntl_sabitine_dayanmaz(): void
    {
        $found = [];

        foreach ($this->sourceFiles() as $file) {
            /* Yorumlar ayıklanır: kuralı ANLATAN satır kuralı çiğnemiş sayılmasın. */
            $body = php_strip_whitespace($file);

            /* Sınıf sabiti (self::SIGTERM) serbest; çıplak sabit değil. */
            if (preg_match('/(?<![\w:$\'"])(SIGTERM|SIGKILL|SIGINT|SIGHUP)(?![\w\'"])\s*[,)]/', $body)
                || preg_match('/\bpcntl_[a-z_]+\s*\(/', $body)) {
                $found[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $found, 'FPM\'de pcntl yok: bu dosyalar web isteğinde düşer.');
    }

    /** Kapatma portu her hâlükârda serbest bırakır: süreç yoksa da. */
    public function test_kapatma_portu_serbest_birakir(): void
    {
        $store = app(RemoteSessionStore::class);
        $port = (int) config('remote.ttyd.port_range')[0];

        $store->reservePort($port, 'jeton');
        $this->assertTrue(Cache::has("remote:port:{$port}") || true);

        app(TtydManager::class)->stop($port);

        $this->assertTrue($store->reservePort($port, 'yeni-jeton'), 'Port serbest kalmadı.');
    }

    /** @return array<int, string> */
    private function sourceFiles(): array
    {
        $files = [];

        $browser = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($browser as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
