<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\RoutingInstanceReader;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * Yönlendirme tabloları (routing-instances).
 *
 * Filtre tabanlı yönlendirmenin merkezi burasıdır. Panel tabloyu okumazsa
 * firewall terimindeki atfı yazabilir ama tablonun kendisini ne listeler ne
 * kurabilir.
 */
final class RoutingInstanceReaderTest extends TestCase
{
    public function test_tablolar_okunur(): void
    {
        $tables = collect($this->read(
            '<routing-instances>'
            .'<instance><name>tablo-a</name><instance-type>forwarding</instance-type>'
            .'<description>musteri tablosu</description></instance>'
            .'<instance><name>ikinci</name><instance-type>virtual-router</instance-type></instance>'
            .'</routing-instances>'
        ))->keyBy('name');

        $this->assertSame('forwarding', $tables['tablo-a']['instance_type']);
        $this->assertSame('musteri tablosu', $tables['tablo-a']['description']);
        $this->assertSame('virtual-router', $tables['ikinci']['instance_type']);
        $this->assertNull($tables['ikinci']['description']);
    }

    /**
     * Tablonun içindeki route'lar burada okunmaz (RouteReader'ın işidir) ama
     * KORUNUR: yazarken geri basılmazsa tablonun kendi yolu silinirdi.
     */
    public function test_tablonun_govdesi_ham_haliyle_korunur(): void
    {
        $table = $this->read(
            '<routing-instances><instance><name>tablo-a</name>'
            .'<instance-type>forwarding</instance-type>'
            .'<routing-options><static><route><name>0.0.0.0/0</name>'
            .'<qualified-next-hop><name>198.51.100.141</name><metric>150</metric></qualified-next-hop>'
            .'</route></static></routing-options>'
            .'</instance></routing-instances>'
        )[0];

        $protected = implode('', $table['residual_xml']);

        $this->assertStringContainsString('qualified-next-hop', $protected);
        $this->assertStringContainsString('198.51.100.141', $protected);
    }

    public function test_tablo_yoksa_bos_liste(): void
    {
        $this->assertSame([], $this->read('<routing-instances/>'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $body): array
    {
        $transport = new FakeNetconfTransport([
            '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability></capabilities></hello>'."\n]]>]]>",
            '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration>'
            .$body.'</configuration></rpc-reply>'."\n]]>]]>",
        ]);

        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (new RoutingInstanceReader)->instances($session);
    }
}
