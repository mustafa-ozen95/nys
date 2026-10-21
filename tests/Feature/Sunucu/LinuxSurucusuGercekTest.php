<?php

declare(strict_types=1);

namespace Tests\Feature\Sunucu;

use App\Services\Sunucu\DondurmeHatasi;
use App\Services\Sunucu\SunucuOturumu;
use App\Services\Sunucu\Surucu\LinuxSurucusu;
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
final class LinuxSurucusuGercekTest extends TestCase
{
    /** @var list<string> */
    private array $komutlar = [];

    private ?string $isaret = null;

    public function test_gecerli_ad_kabuga_gider_ve_tur_calisir(): void
    {
        $surucu = new LinuxSurucusu(5);

        $surucu->kurtarmaAc($this->oturum(), 'nys-kurtarma-ab12cd', 'Parola-1');
        $surucu->kurtarmaSil($this->oturum(), 'nys-kurtarma-ab12cd');

        $hepsi = implode("\n", $this->komutlar);
        $this->assertStringContainsString('useradd -m -s /bin/sh nys-kurtarma-ab12cd', $hepsi);
        $this->assertStringContainsString('chpasswd', $hepsi);
        $this->assertStringContainsString('userdel -r -f nys-kurtarma-ab12cd', $hepsi);
    }

    public function test_kabugu_bozabilecek_ad_makineye_gitmeden_reddedilir(): void
    {
        $surucu = new LinuxSurucusu(5);

        foreach ([
            fn () => $surucu->kurtarmaAc($this->oturum(), 'x; rm -rf /', 'Parola-1'),
            fn () => $surucu->parolaDegistir($this->oturum(), '$(reboot)', 'Parola-1'),
            fn () => $surucu->kurtarmaSil($this->oturum(), 'ad`id`'),
            fn () => $surucu->parolaDegistir($this->oturum(), 'Buyuk', 'Parola-1'),
            fn () => $surucu->kurtarmaAc($this->oturum(), '', 'Parola-1'),
        ] as $cagri) {
            try {
                $cagri();
                $this->fail('Güvensiz hesap adı reddedilmeliydi.');
            } catch (DondurmeHatasi $hata) {
                $this->assertStringContainsString('Hesap adı', $hata->getMessage());
            }
        }

        $this->assertSame([], $this->komutlar, 'Reddedilen ad makineye ulaşmamalıydı.');
    }

    /** Root olarak bağlanan sahte bir SSH oturumu: her komut 0 koduyla biter. */
    private function oturum(): SunucuOturumu
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('isConnected')->andReturn(true);
        $ssh->shouldReceive('enablePTY', 'disablePTY', 'write')->andReturnNull();
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $komut): bool {
            $this->komutlar[] = $komut;
            preg_match('/(NYSRC[0-9a-f]+):/', $komut, $m);
            $this->isaret = $m[1] ?? null;

            return true;
        });
        $ssh->shouldReceive('read')->andReturnUsing(fn () => "\n{$this->isaret}:0\n");

        return new SunucuOturumu($ssh, 'root', 'gizli', static fn () => null);
    }
}
