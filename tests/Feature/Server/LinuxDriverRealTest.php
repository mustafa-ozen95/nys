<?php

declare(strict_types=1);

namespace Tests\Feature\Server;

use App\Services\Server\Driver\LinuxDriver;
use App\Services\Server\RotationError;
use App\Services\Server\ServerSession;
use Mockery;
use phpseclib3\Net\SSH2;
use Tests\TestCase;

/**
 * GERÇEK Linux sürücüsü (sahte değil).
 *
 * Diğer sınamalar SahteSurucu ile çalışıyor; bu yüzden sürücünün kendi
 * içindeki bir kopukluk (var olmayan metodu çağıran hesap adı denetimi)
 * fark edilmeden yaşadı: her gerçek tur "undefined method" ile düşüyordu.
 * Burada yalnızca SSH taşıması sahtelenir; sürücü olduğu gibi çalışır.
 */
final class LinuxDriverRealTest extends TestCase
{
    /** @var list<string> */
    private array $commands = [];

    private ?string $mark = null;

    public function test_gecerli_ad_kabuga_gider_ve_tur_calisir(): void
    {
        $driver = new LinuxDriver(5);

        $driver->recoveryOpen($this->makeSession(), 'nys-kurtarma-ab12cd', 'Parola-1');
        $driver->recoveryDelete($this->makeSession(), 'nys-kurtarma-ab12cd');

        $all = implode("\n", $this->commands);
        $this->assertStringContainsString('useradd -m -s /bin/sh nys-kurtarma-ab12cd', $all);
        $this->assertStringContainsString('chpasswd', $all);
        $this->assertStringContainsString('userdel -r -f nys-kurtarma-ab12cd', $all);
    }

    public function test_kabugu_bozabilecek_ad_makineye_gitmeden_reddedilir(): void
    {
        $driver = new LinuxDriver(5);

        foreach ([
            fn () => $driver->recoveryOpen($this->makeSession(), 'x; rm -rf /', 'Parola-1'),
            fn () => $driver->passwordChange($this->makeSession(), '$(reboot)', 'Parola-1'),
            fn () => $driver->recoveryDelete($this->makeSession(), 'ad`id`'),
            fn () => $driver->passwordChange($this->makeSession(), 'Buyuk', 'Parola-1'),
            fn () => $driver->recoveryOpen($this->makeSession(), '', 'Parola-1'),
        ] as $call) {
            try {
                $call();
                $this->fail('Güvensiz hesap adı reddedilmeliydi.');
            } catch (RotationError $error) {
                $this->assertStringContainsString('Hesap adı', $error->getMessage());
            }
        }

        $this->assertSame([], $this->commands, 'Reddedilen ad makineye ulaşmamalıydı.');
    }

    /** Root olarak bağlanan sahte bir SSH oturumu: her komut 0 koduyla biter. */
    private function makeSession(): ServerSession
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('isConnected')->andReturn(true);
        $ssh->shouldReceive('enablePTY', 'disablePTY', 'write')->andReturnNull();
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command): bool {
            $this->commands[] = $command;
            preg_match('/(NYSRC[0-9a-f]+):/', $command, $m);
            $this->mark = $m[1] ?? null;

            return true;
        });
        $ssh->shouldReceive('read')->andReturnUsing(fn () => "\n{$this->mark}:0\n");

        return new ServerSession($ssh, 'root', 'gizli', static fn () => null);
    }
}
