<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Services\Netconf\EgressGuardReader;
use App\Services\Netconf\FirewallReader;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeNetconfTransport;

/**
 * Çıkış bekçisi listesinin cihazdan ÇIKARILMASI.
 *
 * Sabit veri jn32-test'ten alındı: uplink çıkışına "koruma" filtresi bağlı ve
 * filtre kaynağı "koru" listesinde olanı kabul edip kalanı düşürüyor.
 */
final class EgressGuardReaderTest extends TestCase
{
    public function test_bekci_liste_ve_arayuzu_bulunur(): void
    {
        $guards = $this->read($this->fixture('egress-guard'));

        $this->assertSame(['koru' => ['ge-0/0/23.0']], $guards);
    }

    /**
     * Reddeden bir terimde geçen liste bekçi DEĞİL, kara listedir; oraya blok
     * eklemek tam ters etki yapardı.
     */
    public function test_reddeden_terimdeki_liste_bekci_sayilmaz(): void
    {
        $guards = $this->read($this->reply(
            '<firewall><family><inet><filter><name>engel</name>'
            .'<term><name>t</name><from><source-prefix-list><name>kara</name></source-prefix-list></from>'
            .'<then><discard/></then></term>'
            .'</filter></inet></family></firewall>'
            .'<interfaces><interface><name>ge-0/0/1</name><unit><name>0</name>'
            .'<family><inet><filter><output><filter-name>engel</filter-name></output></filter></inet></family>'
            .'</unit></interface></interfaces>'
        ));

        $this->assertSame([], $guards);
    }

    /**
     * Filtre tanımlı ama hiçbir arayüze bağlı değilse kimseyi korumuyordur.
     */
    public function test_baglanmamis_filtre_bekci_uretmez(): void
    {
        $guards = $this->read($this->reply(
            '<firewall><family><inet><filter><name>koruma</name>'
            .'<term><name>t</name><from><source-prefix-list><name>koru</name></source-prefix-list></from>'
            .'<then><accept/></then></term>'
            .'</filter></inet></family></firewall><interfaces/>'
        ));

        $this->assertSame([], $guards);
    }

    /**
     * Giriş filtresi çıkışı korumaz; blok oraya yazılmamalı.
     */
    public function test_giris_filtresi_sayilmaz(): void
    {
        $guards = $this->read($this->reply(
            '<firewall><family><inet><filter><name>koruma</name>'
            .'<term><name>t</name><from><source-prefix-list><name>koru</name></source-prefix-list></from>'
            .'<then><accept/></then></term>'
            .'</filter></inet></family></firewall>'
            .'<interfaces><interface><name>lo0</name><unit><name>0</name>'
            .'<family><inet><filter><input><filter-name>koruma</filter-name></input></filter></inet></family>'
            .'</unit></interface></interfaces>'
        ));

        $this->assertSame([], $guards);
    }

    public function test_firewall_yoksa_bos_doner(): void
    {
        $this->assertSame([], $this->read($this->reply('<interfaces/>')));
    }

    /**
     * @return array<string, list<string>>
     */
    private function read(string $reply): array
    {
        $transport = new FakeNetconfTransport([$this->hello(), $reply]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (new EgressGuardReader(new FirewallReader))->guards($session);
    }

    private function reply(string $body): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="1">'
            .'<configuration>'.$body.'</configuration></rpc-reply>'."\n]]>]]>";
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
