<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\RibGroupReader;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * RIB grupları — filtre tabanlı yönlendirmenin ÜÇÜNCÜ parçası.
 *
 * Tablo tanımlı ve firewall terimi trafiği oraya atıyor olsa bile, arayüz
 * yolları tabloya kopyalanmazsa geri dönüş yolu bulunamaz. Panel bu parçayı
 * okumazsa kurulumun üçte biri görünmez kalır.
 */
final class RibGroupReaderTest extends TestCase
{
    public function test_gruplar_ve_sirali_tablolar_okunur(): void
    {
        $result = $this->read(
            '<rib-groups><name>rib-grup-a</name>'
            .'<import-rib>tablo-a.inet.0</import-rib><import-rib>inet.0</import-rib></rib-groups>'
            .'<interface-routes><rib-group><inet>rib-grup-a</inet></rib-group></interface-routes>'
        );

        $group = $result['groups'][0];

        $this->assertSame('rib-grup-a', $group['name']);

        // SIRA anlamlıdır: Junos tabloları bu sırayla dener.
        $this->assertSame(['tablo-a.inet.0', 'inet.0'], $group['import_ribs']);

        $this->assertSame('rib-grup-a', $result['interface_routes']);
    }

    /**
     * Arayüz yolu işaretçisi yoksa null döner; "varsayılan bir grup" diye bir
     * şey uydurulmaz.
     */
    public function test_isaretci_yoksa_null(): void
    {
        $result = $this->read('<rib-groups><name>tek</name><import-rib>inet.0</import-rib></rib-groups>');

        $this->assertNull($result['interface_routes']);
    }

    public function test_grup_yoksa_bos_liste(): void
    {
        $result = $this->read('<autonomous-system>65001</autonomous-system>');

        $this->assertSame([], $result['groups']);
        $this->assertNull($result['interface_routes']);
    }

    /**
     * @return array{groups: list<array<string, mixed>>, interface_routes: ?string}
     */
    private function read(string $body): array
    {
        $transport = new FakeNetconfTransport([
            '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability></capabilities></hello>'."\n]]>]]>",
            '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration>'
            .'<routing-options>'.$body.'</routing-options></configuration></rpc-reply>'."\n]]>]]>",
        ]);

        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (new RibGroupReader)->read($session);
    }
}
