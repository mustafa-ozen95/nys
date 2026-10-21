<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Enums\IpFamily;
use App\Enums\RouteProtocol;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\RouteReader;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeNetconfTransport;

/**
 * Sabit değerler lab cihazının (jn32-test, 198.18.20.195) NETCONF çıktısı
 * biçimindedir: tests/Fixtures/netconf/route-information.xml
 */
final class RouteReaderTest extends TestCase
{
    public function test_route_tablosunu_cozumler(): void
    {
        $routes = $this->read($this->fixture('route-information'));

        $this->assertCount(4, $routes);

        $this->assertSame('198.18.8.38', $routes[0]['destination']);
        $this->assertSame(32, $routes[0]['prefix_length']);
        $this->assertSame(RouteProtocol::Local, $routes[0]['protocol']);
        $this->assertSame(IpFamily::Inet, $routes[0]['family']);

        $this->assertSame('198.18.20.0', $routes[1]['destination']);
        $this->assertSame(24, $routes[1]['prefix_length']);
        $this->assertSame(RouteProtocol::Direct, $routes[1]['protocol']);
        $this->assertSame('me0.0', $routes[1]['next_hop']);
    }

    /**
     * Reject ve MultiRecv yollarında sonraki adres yoktur; yolun ne yaptığı
     * kaybolmasın diye nh-type saklanır.
     */
    public function test_sonraki_adresi_olmayan_yolda_tip_saklanir(): void
    {
        $routes = $this->read($this->fixture('route-information'));

        $this->assertSame('Reject', $routes[0]['next_hop']);
        $this->assertSame('MultiRecv', $routes[3]['next_hop']);
        $this->assertSame(RouteProtocol::Other, $routes[3]['protocol']);
    }

    public function test_statik_yol_sonraki_adresi_okunur(): void
    {
        $reply = $this->reply(<<<'XML'
            <route-table>
            <table-name>inet.0</table-name>
            <rt><rt-destination>0.0.0.0/0</rt-destination>
            <rt-entry><protocol-name>Static</protocol-name>
            <nh><to>198.18.8.37</to><via>ge-0/0/23.0</via></nh>
            </rt-entry></rt>
            </route-table>
            XML);

        $routes = $this->read($reply);

        $this->assertSame('0.0.0.0', $routes[0]['destination']);
        $this->assertSame(0, $routes[0]['prefix_length']);
        $this->assertSame('198.18.8.37', $routes[0]['next_hop']);
        $this->assertSame(RouteProtocol::Static, $routes[0]['protocol']);
    }

    public function test_ipv6_tablosu_ayirt_edilir(): void
    {
        $reply = $this->reply(<<<'XML'
            <route-table>
            <table-name>inet6.0</table-name>
            <rt><rt-destination>2001:db8::/32</rt-destination>
            <rt-entry><protocol-name>Static</protocol-name><nh><to>2001:db8::1</to></nh></rt-entry>
            </rt></route-table>
            XML);

        $routes = $this->read($reply);

        $this->assertSame(IpFamily::Inet6, $routes[0]['family']);
        $this->assertSame('2001:db8::', $routes[0]['destination']);
        $this->assertSame(32, $routes[0]['prefix_length']);
    }

    /**
     * mpls.0 ve iso.0 gibi tablolar IP envanterinde işe yaramaz.
     */
    public function test_ip_disi_tablolar_atlanir(): void
    {
        $reply = $this->reply(<<<'XML'
            <route-table>
            <table-name>mpls.0</table-name>
            <rt><rt-destination>0</rt-destination>
            <rt-entry><protocol-name>MPLS</protocol-name></rt-entry></rt>
            </route-table>
            <route-table>
            <table-name>inet.0</table-name>
            <rt><rt-destination>10.0.0.0/8</rt-destination>
            <rt-entry><protocol-name>Static</protocol-name><nh><to>10.0.0.1</to></nh></rt-entry></rt>
            </route-table>
            XML);

        $routes = $this->read($reply);

        $this->assertCount(1, $routes);
        $this->assertSame('10.0.0.0', $routes[0]['destination']);
    }

    /**
     * @return list<array{destination: string, prefix_length: int, next_hop: ?string, protocol: RouteProtocol, family: IpFamily}>
     */
    private function read(string $reply): array
    {
        $transport = new FakeNetconfTransport([$this->hello(), $reply]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (new RouteReader)->routes($session);
    }

    private function reply(string $body): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="1">'
            .'<route-information>'.$body.'</route-information></rpc-reply>'."\n]]>]]>";
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
