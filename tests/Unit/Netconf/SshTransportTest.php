<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Services\Netconf\NetconfException;
use App\Services\Netconf\Transport\SshSubsystemTransport;
use phpseclib3\Exception\ConnectionClosedException;
use phpseclib3\Exception\TimeoutException;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Taşıma katmanının iki sözü:
 *
 * 1. Okuma bayt akışıdır: "şu diziye kadar" ve "tam şu kadar bayt" AYNI
 *    tampondan beslenir. base:1.1 çerçevelemesi buna dayanıyor.
 * 2. phpseclib istisnaları dışarı SIZMAZ. TimeoutException ve
 *    ConnectionClosedException \RuntimeException'dan türer; çevrilmezlerse
 *    kod tabanındaki hiçbir catch (NetconfException) onları yakalamaz ve
 *    kullanıcı hata mesajı yerine 500 sayfası görür.
 *
 * Gerçek bağlantı açılmaz (bkz. .ai/rules/services.md): SSH2 yerine yapıcısı
 * çalıştırılmayan bir alt sınıf konur.
 */
final class SshTransportTest extends TestCase
{
    public function test_okuma_ayni_tampondan_beslenir(): void
    {
        $transport = $this->transportWith(new StubSsh(["\n#12\n<rpc-", 'reply/>', "\n##\n"]));

        // Başlık satırı "\n" ile biter; ardından TAM 12 bayt gelir. İki okuma
        // biçimi aynı paketleri paylaşıyor: ilk paket hem başlığı hem verinin
        // bir kısmını taşıyor.
        $this->assertSame("\n", $transport->readUntil("\n"));
        $this->assertSame("#12\n", $transport->readUntil("\n"));
        $this->assertSame('<rpc-reply/>', $transport->readExactly(12));
        $this->assertSame("\n##\n", $transport->readUntil("\n##\n"));
    }

    /**
     * Cihaz oturumu DÜŞÜRDÜYSE mesaj operatöre ne olduğunu söylemeli.
     *
     * Junos'un oturumu düşürdüğü ölçüldü: geçersiz bir öznitelik değeri
     * (get-configuration database="olmayan") oturumu anında kapatıyor.
     * Sonrasında her istek SSH katmanında patlıyordu ve phpseclib'in iç dili
     * ("Unable to request pty") operatörün önüne çıkıyordu.
     */
    public function test_cihaz_oturumu_dusurunce_anlasilir_mesaj_verir(): void
    {
        $transport = $this->transportWith(new StubSsh([], new ConnectionClosedException('Connection closed by server')));

        $this->expectException(NetconfException::class);
        $this->expectExceptionMessage('Cihaz NETCONF oturumunu düşürdü');

        $transport->readUntil("\n");
    }

    public function test_zaman_asimi_netconf_hatasi_verir(): void
    {
        $ssh = new StubSsh([], new TimeoutException('Timed out waiting for server'));
        $ssh->timedOut = true;

        $this->expectException(NetconfException::class);
        $this->expectExceptionMessage('zaman aşımına uğradı');

        $this->transportWith($ssh)->readExactly(4);
    }

    public function test_yazarken_kopan_baglanti_da_cevrilir(): void
    {
        $transport = $this->transportWith(new StubSsh([], new ConnectionClosedException('Connection closed by server')));

        $this->expectException(NetconfException::class);

        $transport->write('<rpc/>');
    }

    /**
     * Karşı taraf veri vermeden kapanırsa okuma sonsuza kadar beklememeli.
     */
    public function test_veri_bitince_beklemez(): void
    {
        $transport = $this->transportWith(new StubSsh(['kısmi']));

        $this->expectException(NetconfException::class);
        $this->expectExceptionMessage('yanıt tamamlanmadan kapandı');

        $transport->readUntil("\n");
    }

    private function transportWith(SSH2 $ssh): SshSubsystemTransport
    {
        $transport = new SshSubsystemTransport;

        $property = new ReflectionProperty(SshSubsystemTransport::class, 'ssh');
        $property->setValue($transport, $ssh);

        return $transport;
    }
}

/**
 * Yapıcısı hiçbir şeye bağlanmayan SSH2. Paketler sırayla verilir; sıra
 * bitince phpseclib'in gerçek davranışı taklit edilir (kanal kapandığında
 * true döner).
 */
final class StubSsh extends SSH2
{
    public bool $timedOut = false;

    /**
     * @param  list<string>  $packets
     */
    public function __construct(private array $packets = [], private ?\Throwable $failure = null) {}

    public function read($expect = '', $mode = SSH2::READ_SIMPLE, $channel = null)
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->packets === [] ? true : array_shift($this->packets);
    }

    public function write($cmd, $channel = null)
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return true;
    }

    public function isTimeout()
    {
        return $this->timedOut;
    }

    public function disconnect() {}

    /**
     * Oturum kaybı OLMAYAN bir taşıma hatası genel mesajı korumalı; yeni
     * çeviri her hatayı yutmamalı.
     */
    public function test_baska_tasima_hatasi_genel_mesaji_korur(): void
    {
        $transport = $this->transportWith(new StubSsh([], new ConnectionClosedException('Kırık paket')));

        $this->expectException(NetconfException::class);
        $this->expectExceptionMessage('NETCONF bağlantısı koptu');

        $transport->readUntil("\n");
    }
}
