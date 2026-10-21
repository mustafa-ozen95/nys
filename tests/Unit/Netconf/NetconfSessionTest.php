<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\NetconfSession;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeNetconfTransport;

/**
 * Sabit değerler jn32-test (EX4200, JunOS 12.3R12.4) üzerinden alınan gerçek
 * NETCONF çıktısıdır.
 */
final class NetconfSessionTest extends TestCase
{
    /** @var list<string> */
    private const CLIENT_CAPABILITIES = [
        'urn:ietf:params:netconf:base:1.0',
        'urn:ietf:params:netconf:base:1.1',
    ];

    public function test_hello_okunur_ve_cihaz_yetenekleri_acilir(): void
    {
        $transport = new FakeNetconfTransport([$this->deviceHello()]);
        $session = new NetconfSession($transport, new FramingNegotiator);

        $session->handshake(self::CLIENT_CAPABILITIES);

        $this->assertContains('urn:ietf:params:netconf:base:1.0', $session->deviceCapabilities());
        $this->assertContains('urn:ietf:params:netconf:capability:confirmed-commit:1.0', $session->deviceCapabilities());
        $this->assertContains('http://xml.juniper.net/netconf/junos/1.0', $session->deviceCapabilities());
    }

    public function test_eski_cihazla_1_0_cerceveleme_konusulur(): void
    {
        $transport = new FakeNetconfTransport([$this->deviceHello()]);
        $session = new NetconfSession($transport, new FramingNegotiator);

        $session->handshake(self::CLIENT_CAPABILITIES);

        $this->assertSame('urn:ietf:params:netconf:base:1.0', $session->framingCapability());
        $this->assertStringEndsWith("]]>]]>\n", $transport->written[0]);
    }

    public function test_1_1_bildiren_cihazla_parcali_cerceveleme_konusulur(): void
    {
        $hello = str_replace(
            '<capability>urn:ietf:params:netconf:base:1.0</capability>',
            '<capability>urn:ietf:params:netconf:base:1.0</capability><capability>urn:ietf:params:netconf:base:1.1</capability>',
            $this->deviceHello(),
        );

        $transport = new FakeNetconfTransport([$hello, "\n#76\n<rpc-reply xmlns=\"urn:ietf:params:xml:ns:netconf:base:1.0\"><ok/></rpc-reply>\n##\n"]);
        $session = new NetconfSession($transport, new FramingNegotiator);

        $session->handshake(self::CLIENT_CAPABILITIES);
        $session->rpc('<get-software-information/>');

        $this->assertSame('urn:ietf:params:netconf:base:1.1', $session->framingCapability());

        // Hello daima 1.0 biçiminde, sonraki mesaj parçalı gider.
        $this->assertStringEndsWith("]]>]]>\n", $transport->written[0]);
        $this->assertStringStartsWith("\n#", $transport->written[1]);
        $this->assertStringEndsWith("\n##\n", $transport->written[1]);
    }

    public function test_rpc_yaniti_cozumlenir(): void
    {
        $reply = <<<'XML'
            <rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="1">
            <software-information>
            <host-name>sw-test.ornek.test</host-name>
            <product-model>ex4200-24t</product-model>
            </software-information>
            </rpc-reply>
            ]]>]]>
            XML;

        $transport = new FakeNetconfTransport([$this->deviceHello(), $reply]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(self::CLIENT_CAPABILITIES);

        $result = $session->rpc('<get-software-information/>');

        $names = $result->xpath('//*[local-name()="host-name"]');
        $this->assertSame('sw-test.ornek.test', trim((string) $names[0]));
    }

    public function test_her_rpc_artan_message_id_tasir(): void
    {
        $ok = '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><ok/></rpc-reply>'."\n]]>]]>";

        $transport = new FakeNetconfTransport([$this->deviceHello(), $ok, $ok]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(self::CLIENT_CAPABILITIES);

        $session->rpc('<get-software-information/>');
        $session->rpc('<get-chassis-inventory/>');

        $this->assertStringContainsString('message-id="1"', $transport->written[1]);
        $this->assertStringContainsString('message-id="2"', $transport->written[2]);
    }

    public function test_cihaz_hatasi_istisnaya_cevrilir(): void
    {
        $error = <<<'XML'
            <rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="1">
            <rpc-error>
            <error-severity>error</error-severity>
            <error-message>syntax error</error-message>
            </rpc-error>
            </rpc-reply>
            ]]>]]>
            XML;

        $transport = new FakeNetconfTransport([$this->deviceHello(), $error]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(self::CLIENT_CAPABILITIES);

        $this->expectException(NetconfException::class);
        $this->expectExceptionMessageMatches('/syntax error/');

        $session->rpc('<get-configuration/>');
    }

    public function test_kapatirken_close_session_gonderilir_ve_baglanti_kapanir(): void
    {
        $transport = new FakeNetconfTransport([$this->deviceHello()]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(self::CLIENT_CAPABILITIES);

        $session->close();

        $this->assertStringContainsString('<close-session/>', end($transport->written));
        $this->assertSame(1, $transport->closeCount);
        $this->assertFalse($transport->isOpen);
    }

    public function test_bos_yanit_istisnaya_cevrilir(): void
    {
        $transport = new FakeNetconfTransport(['']);
        $session = new NetconfSession($transport, new FramingNegotiator);

        $this->expectException(NetconfException::class);

        $session->handshake(self::CLIENT_CAPABILITIES);
    }

    private function deviceHello(): string
    {
        return <<<'XML'
            <!-- No zombies were killed during the creation of this user interface -->
            <!-- user admin, class j-super-user -->
            <hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0">
              <capabilities>
                <capability>urn:ietf:params:netconf:base:1.0</capability>
                <capability>urn:ietf:params:netconf:capability:candidate:1.0</capability>
                <capability>urn:ietf:params:netconf:capability:confirmed-commit:1.0</capability>
                <capability>urn:ietf:params:netconf:capability:validate:1.0</capability>
                <capability>http://xml.juniper.net/netconf/junos/1.0</capability>
              </capabilities>
              <session-id>1494</session-id>
            </hello>
            ]]>]]>
            XML;
    }
}
