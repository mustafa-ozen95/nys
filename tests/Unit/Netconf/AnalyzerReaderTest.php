<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Models\SwitchAnalyzer;
use App\Services\Netconf\AnalyzerReader;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * Port aynalama okuyucusu.
 *
 * Yazma yolu analyzer düğümünü BÜTÜN OLARAK yeniden kurar (replace="replace");
 * bu yüzden okunmayan her satır ilk güncellemede sessizce kaybolurdu. Bu
 * testler iki şeyi kilitler:
 *
 *   1. Modellenen alanlar gerçekten okunuyor mu — hem <interface><name>..</name>
 *      hem <interface>..</interface> yazımıyla.
 *   2. Panelin tanımadığı satırlar HANGİ SEVİYEDE olurlarsa olsunlar ham
 *      hâlleriyle korunuyor mu.
 */
final class AnalyzerReaderTest extends TestCase
{
    public function test_aynalama_alanlari_okunur(): void
    {
        $analyzers = $this->read(
            '<ethernet-switching-options><analyzer>'
            .'<name>izleme</name><ratio>2</ratio><loss-priority>low</loss-priority>'
            .'<input>'
            .'<ingress><interface><name>ge-0/0/5.0</name></interface><vlan><name>ofis</name></vlan></ingress>'
            .'<egress><interface><name>ge-0/0/6.0</name></interface></egress>'
            .'</input>'
            .'<output><interface><name>ge-0/0/21.0</name></interface></output>'
            .'</analyzer></ethernet-switching-options>'
        );

        $this->assertCount(1, $analyzers);
        $this->assertSame('izleme', $analyzers[0]['name']);
        $this->assertSame(2, $analyzers[0]['ratio']);
        $this->assertSame('low', $analyzers[0]['loss_priority']);
        $this->assertSame(['ge-0/0/5.0'], $analyzers[0]['ingress_interfaces']);
        $this->assertSame(['ofis'], $analyzers[0]['ingress_vlans']);
        $this->assertSame(['ge-0/0/6.0'], $analyzers[0]['egress_interfaces']);
        $this->assertSame('ge-0/0/21.0', $analyzers[0]['output_interface']);
        $this->assertNull($analyzers[0]['output_vlan']);
    }

    /**
     * Junos aynı listeyi iki yazımla döndürebilir. Yalnızca birine bakan
     * okuyucu cihazda duran kaynağı hiç görmez ve ilk güncellemede siler.
     */
    public function test_duz_metin_yazimi_da_okunur(): void
    {
        $analyzers = $this->read(
            '<ethernet-switching-options><analyzer><name>izleme</name>'
            .'<input><ingress><interface>ge-0/0/5.0</interface></ingress></input>'
            .'</analyzer></ethernet-switching-options>'
        );

        $this->assertSame(['ge-0/0/5.0'], $analyzers[0]['ingress_interfaces']);
    }

    /**
     * Çıkış hedefi VLAN olabilir (uzak aynalama).
     */
    public function test_cikis_vlani_okunur(): void
    {
        $analyzers = $this->read(
            '<ethernet-switching-options><analyzer><name>uzak</name>'
            .'<output><vlan><name>ayna-vlan</name></vlan></output>'
            .'</analyzer></ethernet-switching-options>'
        );

        $this->assertSame('ayna-vlan', $analyzers[0]['output_vlan']);
        $this->assertNull($analyzers[0]['output_interface']);
    }

    /**
     * Panelin tanımadığı satır KAYBOLMAZ; hangi seviyede durduğu da korunur,
     * yoksa geri yazarken yanlış yere basılır ve cihaz reddeder.
     */
    public function test_taninmayan_satirlar_seviyesiyle_korunur(): void
    {
        $analyzers = $this->read(
            '<ethernet-switching-options><analyzer>'
            .'<name>izleme</name><uydurma-analyzer/>'
            .'<input><uydurma-input/>'
            .'<ingress><interface><name>ge-0/0/5.0</name></interface><uydurma-ingress/></ingress>'
            .'<egress><uydurma-egress/></egress>'
            .'</input>'
            .'<output><interface><name>ge-0/0/21.0</name></interface><uydurma-output/></output>'
            .'</analyzer></ethernet-switching-options>'
        );

        $residual = $analyzers[0]['residual_xml'];

        $this->assertSame(['<uydurma-analyzer/>'], $residual[SwitchAnalyzer::RESIDUAL_ROOT]);
        $this->assertSame(['<uydurma-input/>'], $residual[SwitchAnalyzer::RESIDUAL_INPUT]);
        $this->assertSame(['<uydurma-ingress/>'], $residual[SwitchAnalyzer::RESIDUAL_INGRESS]);
        $this->assertSame(['<uydurma-egress/>'], $residual[SwitchAnalyzer::RESIDUAL_EGRESS]);
        $this->assertSame(['<uydurma-output/>'], $residual[SwitchAnalyzer::RESIDUAL_OUTPUT]);
    }

    /**
     * Çıkış VLAN'ının altındaki ek satırlar (no-tag gibi) modellenmiyor ama
     * korunuyor: modellenmiş gibi davranmak, ölçülmemiş bir alanı forma
     * koymak olurdu.
     */
    public function test_cikis_vlani_altindaki_satir_korunur(): void
    {
        $analyzers = $this->read(
            '<ethernet-switching-options><analyzer><name>uzak</name>'
            .'<output><vlan><name>ayna-vlan</name><no-tag/></vlan></output>'
            .'</analyzer></ethernet-switching-options>'
        );

        $this->assertSame(
            ['<no-tag/>'],
            $analyzers[0]['residual_xml'][SwitchAnalyzer::RESIDUAL_OUTPUT_VLAN],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $body): array
    {
        $reply = '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="1">'
            .'<configuration>'.$body.'</configuration></rpc-reply>'."\n]]>]]>";

        $hello = '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability></capabilities></hello>'."\n]]>]]>";

        $session = new NetconfSession(new FakeNetconfTransport([$hello, $reply]), new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (new AnalyzerReader)->analyzers($session);
    }
}
