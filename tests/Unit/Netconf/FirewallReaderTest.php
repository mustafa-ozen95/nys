<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Enums\FirewallAction;
use App\Enums\FirewallFamily;
use App\Services\Netconf\FirewallReader;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeNetconfTransport;

/**
 * Firewall filtresi panelin EN TEHLİKELİ yazma yoludur: güncelleme filtreyi
 * silip bütün terimleriyle yeniden yazar (terim sırası anlamlı olduğu için).
 *
 * Okuyucu "panelin sadakatle yeniden üretemeyeceği" filtreyi yönetilemez
 * saymaz; sayarsa kullanıcı o filtreyi hiç yönetemez. İki katman var ve bu
 * testler ikisini de kilitliyor:
 *
 *   1. Modellenen alanlar gerçekten okunuyor mu (üç aile, filtre seviyesi,
 *      genişletilmiş koşullar ve eylemler, policer'lar).
 *   2. Modellenmeyen her satır ham hâliyle korunuyor mu — hiçbir filtre salt
 *      okunur değil, çünkü hiçbir satır kaybolmuyor.
 */
final class FirewallReaderTest extends TestCase
{
    public function test_filtre_ve_terimleri_okunur(): void
    {
        $filters = $this->read(
            '<firewall><family><inet><filter><name>koruma</name>'
            .'<term><name>izin</name>'
            .'<from><source-prefix-list><name>kabul</name></source-prefix-list><protocol>tcp</protocol></from>'
            .'<then><accept/><count>sayac</count></then></term>'
            .'<term><name>gerisi</name><then><discard/></then></term>'
            .'</filter></inet></family></firewall>'
        );

        $this->assertCount(1, $filters);
        $this->assertSame('koruma', $filters[0]['name']);
        $this->assertSame(FirewallFamily::Inet, $filters[0]['family']);
        $this->assertTrue($filters[0]['is_manageable']);

        $this->assertSame(['kabul'], $filters[0]['terms'][0]['source_prefix_lists']);
        $this->assertSame(['tcp'], $filters[0]['terms'][0]['protocols']);
        $this->assertSame(FirewallAction::Accept, $filters[0]['terms'][0]['action']);
        $this->assertSame('sayac', $filters[0]['terms'][0]['count_name']);

        // Sıra anlamlıdır: cihazda terimler yukarıdan aşağı değerlendirilir.
        $this->assertSame(0, $filters[0]['terms'][0]['position']);
        $this->assertSame(1, $filters[0]['terms'][1]['position']);
    }

    /**
     * EX3200/EX4200'de filtre anahtarlama düzleminde de kurulabilir; o aile
     * IpFamily'de yoktur ve okunmadığı sürece filtre "IPv4" sanılırdı.
     */
    public function test_uc_aile_de_okunur(): void
    {
        $filters = $this->read(
            '<firewall><family>'
            .'<inet><filter><name>dort</name><term><name>t</name><then><accept/></then></term></filter></inet>'
            .'<inet6><filter><name>alti</name><term><name>t</name><then><accept/></then></term></filter></inet6>'
            .'<ethernet-switching><filter><name>anahtar</name><term><name>t</name><then><accept/></then></term></filter></ethernet-switching>'
            .'</family></firewall>'
        );

        $this->assertSame(
            [FirewallFamily::Inet, FirewallFamily::Inet6, FirewallFamily::EthernetSwitching],
            array_column($filters, 'family'),
        );
    }

    /**
     * "interface-specific" filtrenin kendi altındadır (arayüz başına sayaç
     * için gerekir) ve cihazda geçerli olduğu ölçüldü — test1, commit check.
     * Panel onu okumadığı sürece her güncellemede sessizce siliniyordu.
     */
    public function test_filtre_seviyesindeki_interface_specific_okunur(): void
    {
        $filters = $this->read(
            '<firewall><family><inet><filter><name>koruma</name>'
            .'<interface-specific/>'
            .'<term><name>t</name><then><accept/></then></term>'
            .'</filter></inet></family></firewall>'
        );

        $this->assertTrue($filters[0]['interface_specific']);
        $this->assertTrue($filters[0]['is_manageable']);
        $this->assertSame([], $filters[0]['residual_xml']);
    }

    /**
     * EN ÖNEMLİSİ: panelin tanımadığı filtre satırı KAYBOLMAZ.
     *
     * Böyle bir filtre salt okunur ilan edilmez; satır ham hâliyle saklanır
     * ve geri yazılırken aynen basılır.
     */
    public function test_filtre_seviyesindeki_bilinmeyen_satir_korunur(): void
    {
        $filters = $this->read(
            '<firewall><family><inet><filter><name>koruma</name>'
            .'<physical-interface-filter/>'
            .'<term><name>t</name><then><accept/></then></term>'
            .'</filter></inet></family></firewall>'
        );

        $this->assertTrue($filters[0]['is_manageable']);
        $this->assertSame(['<physical-interface-filter/>'], $filters[0]['residual_xml']);
    }

    /**
     * Terimin tanımadığı satırı ÜÇ yerde birden olabilir: terimin kendi
     * altında, "from"un ve "then"in altında. Gövde yazarken üçü de yeniden
     * kurulur; yalnızca biri saklansaydı diğerleri yine kaybolurdu.
     */
    public function test_terimdeki_bilinmeyen_satirlar_uc_yerde_de_korunur(): void
    {
        $filters = $this->read(
            '<firewall><family><inet><filter><name>koruma</name>'
            .'<term><name>t</name>'
            .'<uydurma-terim/>'
            .'<from><flexible-match-mask><name>x</name></flexible-match-mask><protocol>tcp</protocol></from>'
            .'<then><uydurma-eylem>1</uydurma-eylem><accept/></then>'
            .'</term>'
            .'</filter></inet></family></firewall>'
        );

        $term = $filters[0]['terms'][0];

        $this->assertTrue($filters[0]['is_manageable']);
        $this->assertSame(['<uydurma-terim/>'], $term['residual_xml']);
        $this->assertSame(['<flexible-match-mask><name>x</name></flexible-match-mask>'], $term['from_residual_xml']);
        $this->assertSame(['<uydurma-eylem>1</uydurma-eylem>'], $term['then_residual_xml']);

        // Tanınan koşul ve eylem artıkta DEĞİL, kendi alanında durur.
        $this->assertSame(['tcp'], $term['protocols']);
        $this->assertSame(FirewallAction::Accept, $term['action']);
    }

    /**
     * Policer ve genişletilmiş koşullar filtreyi salt okunur yapmaz.
     */
    public function test_genisletilmis_kosullar_ve_eylemler_okunur(): void
    {
        $filters = $this->read(
            '<firewall><family><inet><filter><name>koruma</name>'
            .'<term><name>t</name>'
            .'<from>'
            .'<tcp-flags>syn</tcp-flags><tcp-established/>'
            .'<dscp>ef</dscp><packet-length>1400</packet-length><ttl>10-20</ttl><is-fragment/>'
            .'<ip-options>router-alert</ip-options><precedence>routine</precedence>'
            .'<interface>ge-0/0/5.0</interface><icmp-code>3</icmp-code>'
            .'</from>'
            .'<then><policer>hizli</policer><forwarding-class>best-effort</forwarding-class>'
            .'<loss-priority>low</loss-priority><syslog/><sample/><next>term</next></then>'
            .'</term>'
            .'</filter></inet></family></firewall>'
        );

        $term = $filters[0]['terms'][0];

        $this->assertSame('syn', $term['tcp_flags']);
        $this->assertTrue($term['is_tcp_established']);
        $this->assertFalse($term['is_tcp_initial']);
        $this->assertSame(['ef'], $term['dscps']);
        $this->assertSame(['1400'], $term['packet_lengths']);
        // Trafik paket boyu + ttl ile ayıklanabilir; koşul modellenmezse
        // panelden ne görülür ne değiştirilebilirdi.
        $this->assertSame(['10-20'], $term['ttls']);
        $this->assertTrue($term['is_fragment']);
        $this->assertSame(['router-alert'], $term['ip_options']);
        $this->assertSame(['routine'], $term['precedences']);
        $this->assertSame(['ge-0/0/5.0'], $term['interfaces']);
        $this->assertSame(['3'], $term['icmp_codes']);

        $this->assertSame('hizli', $term['policer']);
        $this->assertSame('best-effort', $term['forwarding_class']);
        $this->assertSame('low', $term['loss_priority']);
        $this->assertTrue($term['is_syslogged']);
        $this->assertTrue($term['is_sampled']);
        $this->assertTrue($term['goes_to_next_term']);

        // Hiçbir tanınan satır artığa düşmemeli.
        $this->assertSame([], $term['from_residual_xml']);
        $this->assertSame([], $term['then_residual_xml']);
    }

    /**
     * "except" koşulu TERSİNE çevirir: okunmazsa panel onu normal bir eşleşme
     * sanıp geri yazar ve filtre sessizce ters çalışır.
     */
    public function test_prefix_list_except_isareti_okunur(): void
    {
        $filters = $this->read(
            '<firewall><family><inet><filter><name>koruma</name>'
            .'<term><name>t</name><from>'
            .'<source-prefix-list><name>ic</name></source-prefix-list>'
            .'<source-prefix-list><name>dis</name><except/></source-prefix-list>'
            .'</from><then><accept/></then></term>'
            .'</filter></inet></family></firewall>'
        );

        $term = $filters[0]['terms'][0];

        $this->assertSame(['ic', 'dis'], $term['source_prefix_lists']);
        $this->assertSame(['dis'], $term['source_prefix_list_excepts']);
    }

    /**
     * inet6 ve ethernet-switching koşulları kendi alanlarına okunur.
     */
    public function test_aileye_ozel_kosullar_okunur(): void
    {
        $filters = $this->read(
            '<firewall><family>'
            .'<inet6><filter><name>alti</name><term><name>t</name>'
            .'<from><next-header>tcp</next-header><traffic-class>ef</traffic-class></from>'
            .'<then><accept/></then></term></filter></inet6>'
            .'<ethernet-switching><filter><name>anahtar</name><term><name>t</name>'
            .'<from><ether-type>arp</ether-type>'
            .'<source-mac-address><name>00:11:22:33:44:55</name></source-mac-address></from>'
            .'<then><discard/></then></term></filter></ethernet-switching>'
            .'</family></firewall>'
        );

        $this->assertSame(['tcp'], $filters[0]['terms'][0]['next_headers']);
        $this->assertSame(['ef'], $filters[0]['terms'][0]['traffic_classes']);
        $this->assertSame(['arp'], $filters[1]['terms'][0]['ether_types']);
        $this->assertSame(['00:11:22:33:44:55'], $filters[1]['terms'][0]['source_mac_addresses']);
    }

    /**
     * Policer cihazda filtrenin DIŞINDA, "firewall" düğümünün doğrudan
     * altındadır. Panel onu hiç okumuyordu.
     */
    public function test_policerlar_okunur(): void
    {
        $policers = $this->readAll(
            '<firewall>'
            .'<policer><name>musteri-10m</name><filter-specific/>'
            .'<if-exceeding><bandwidth-limit>10m</bandwidth-limit><burst-size-limit>15k</burst-size-limit></if-exceeding>'
            .'<then><discard/></then>'
            .'<uydurma-policer-satiri/>'
            .'</policer>'
            .'</firewall>'
        )['policers'];

        $this->assertCount(1, $policers);
        $this->assertSame('musteri-10m', $policers[0]['name']);
        $this->assertTrue($policers[0]['filter_specific']);
        $this->assertSame('10m', $policers[0]['bandwidth_limit']);
        $this->assertSame('15k', $policers[0]['burst_size_limit']);
        $this->assertTrue($policers[0]['is_discard']);
        $this->assertSame(['<uydurma-policer-satiri/>'], $policers[0]['residual_xml']);
    }

    public function test_arayuz_baglamalari_okunur(): void
    {
        $filters = $this->read(
            '<firewall><family><inet><filter><name>koruma</name>'
            .'<term><name>t</name><then><accept/></then></term>'
            .'</filter></inet></family></firewall>'
            .'<interfaces><interface><name>ge-0/0/5</name><unit><name>0</name>'
            .'<family><inet><filter><output><filter-name>koruma</filter-name></output></filter></inet></family>'
            .'</unit></interface></interfaces>'
        );

        $this->assertSame(
            [['interface' => 'ge-0/0/5.0', 'direction' => 'output']],
            $filters[0]['bindings'],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $body): array
    {
        return $this->readAll($body)['filters'];
    }

    /**
     * @return array{filters: list<array<string, mixed>>, policers: list<array<string, mixed>>}
     */
    private function readAll(string $body): array
    {
        $reply = '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="1">'
            .'<configuration>'.$body.'</configuration></rpc-reply>'."\n]]>]]>";

        $hello = '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability></capabilities></hello>'."\n]]>]]>";

        $session = new NetconfSession(new FakeNetconfTransport([$hello, $reply]), new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (new FirewallReader)->read($session);
    }
}
