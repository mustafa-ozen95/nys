<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\ReceivedRouteReader;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeNetconfTransport;

/**
 * Alınan prefixler, iki lab cihazı (jn32-test, jn33-test) arasındaki bir
 * anonsun çıktısı biçimiyle sınanır: komşu iki blok duyurur, içeri alma
 * politikası birini kabul edip diğerini reddeder.
 */
final class ReceivedRouteReaderTest extends TestCase
{
    public function test_kabul_edilen_ve_reddedilen_ayrilir(): void
    {
        $sonuc = $this->read($this->fixture('received-routes'));

        $this->assertSame(['192.168.50.0/24'], array_column($sonuc['accepted'], 'prefix'));
        $this->assertSame(['192.168.51.0/24'], array_column($sonuc['rejected'], 'prefix'));
    }

    public function test_yol_ayrintilari_okunur(): void
    {
        $kabul = $this->read($this->fixture('received-routes'))['accepted'][0];

        $this->assertSame('65001 I', $kabul['as_path']);
        $this->assertSame('198.18.8.37', $kabul['next_hop']);
    }

    /**
     * Reddedilen yolda active-tag ELEMANI VARDIR ama içi tek boşluktur;
     * varlığına bakan bir kontrol ikisini ayırt edemez.
     */
    public function test_bos_active_tag_kabul_sayilmaz(): void
    {
        $sonuc = $this->read($this->reply(
            '<route-information><route-table><table-name>inet.0</table-name>'
            .'<rt><rt-destination>10.1.0.0/24</rt-destination>'
            .'<rt-entry><active-tag> </active-tag></rt-entry></rt>'
            .'</route-table></route-information>'
        ));

        $this->assertSame([], $sonuc['accepted']);
        $this->assertCount(1, $sonuc['rejected']);
    }

    /**
     * Junos cihazın iç tablolarını da döndürür; onlar panelin verisi değildir.
     */
    public function test_cihazin_ic_tablolari_atlanir(): void
    {
        $sonuc = $this->read($this->reply(
            '<route-information>'
            .'<route-table><table-name>__juniper_private1__.inet.0</table-name>'
            .'<rt><rt-destination>10.9.9.0/24</rt-destination>'
            .'<rt-entry><active-tag>*</active-tag></rt-entry></rt></route-table>'
            .'</route-information>'
        ));

        $this->assertSame([], $sonuc['accepted']);
        $this->assertSame([], $sonuc['rejected']);
    }

    public function test_hic_prefix_alinmamissa_bos_doner(): void
    {
        $sonuc = $this->read($this->reply('<route-information/>'));

        $this->assertSame([], $sonuc['accepted']);
        $this->assertSame([], $sonuc['rejected']);
    }

    /**
     * @return array{accepted: list<array<string, mixed>>, rejected: list<array<string, mixed>>}
     */
    private function read(string $reply): array
    {
        $transport = new FakeNetconfTransport([$this->hello(), $reply]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (new ReceivedRouteReader)->forNeighbor($session, '198.18.8.37');
    }

    private function reply(string $body): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="1">'
            .$body.'</rpc-reply>'."\n]]>]]>";
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__."/../../Fixtures/netconf/{$name}.xml")."\n]]>]]>";
    }

    private function hello(): string
    {
        return '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability></capabilities></hello>'."\n]]>]]>";
    }
}
