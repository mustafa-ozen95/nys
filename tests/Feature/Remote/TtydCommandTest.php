<?php

declare(strict_types=1);

namespace Tests\Feature\Remote;

use App\Enums\ConnectionProtocol;
use App\Models\NetworkSwitch;
use App\Services\Remote\RemoteTarget;
use App\Services\Remote\TtydManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Terminal süreci için kurulan komut satırı.
 *
 * KULLANICI ADI KONAĞA YAPIŞTIRILMAZ. Tek argüman ("kullanici@konak")
 * verilirse ssh o argümanı, tire ile başlıyorsa, bir SEÇENEK olarak
 * ayrıştırır. Sömürülebilir değildir — geriye konak kalmadığı için ssh
 * kullanım metnini basıp çıkar ve ProxyCommand hiç çalışmaz — ama bağlantı
 * anlaşılmaz bir hatayla düşer.
 */
class TtydCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    private function command(string $user, string $host = '10.0.0.5'): array
    {
        $target = new RemoteTarget(
            subject: NetworkSwitch::factory()->create(),
            label: 'Sınama',
            host: $host,
            protocol: ConnectionProtocol::Ssh,
            port: 22,
            username: $user,
            password: 'parola',
        );

        $method = new ReflectionMethod(TtydManager::class, 'command');

        return $method->invoke(app(TtydManager::class), $target, 7500);
    }

    #[Test]
    public function kullanici_adi_ayri_argumanda_gecer(): void
    {
        $command = $this->command('root');

        $place = array_search('-l', $command, true);

        $this->assertNotFalse($place, 'Kullanıcı adı "-l" ile geçmeli.');
        $this->assertSame('root', $command[$place + 1]);
        $this->assertSame('10.0.0.5', $command[array_key_last($command)], 'Konak son argüman olmalı.');

        /* Yapıştırılmış biçim hiçbir argümanda kalmamalı. */
        $this->assertNotContains('root@10.0.0.5', $command);
    }

    /**
     * ASIL SINAMA BU: tire ile başlayan kullanıcı adı.
     *
     * Ad "-l"nin DEĞERİ olduğu için ssh onu seçenek saymaz.
     */
    #[Test]
    public function tireyle_baslayan_kullanici_adi_secenege_donusmez(): void
    {
        $name = '-oProxyCommand=yes';
        $command = $this->command($name);

        $place = array_search('-l', $command, true);

        $this->assertNotFalse($place);
        $this->assertSame($name, $command[$place + 1], 'Ad "-l" değerinde durmalı.');

        /* Konak hâlâ son argüman: ssh bağlanacak bir yer bulabiliyor. */
        $this->assertSame('10.0.0.5', $command[array_key_last($command)]);
    }
}
