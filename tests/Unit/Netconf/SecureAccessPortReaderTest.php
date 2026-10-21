<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\SecureAccessPortReader;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * DHCP snooping / port güvenliği okuması.
 *
 * Bu okuyucunun asıl sınavı "anlamadığını ne yaptığı"dır. Panel bir satırı
 * BİLİNEN sayarsa onu ham hâliyle korumaz ve düzenlemede kendi ürettiğini
 * yazar; ayrıştıramadığı bir biçimi bilinen sayarsa satır önce ekranda
 * kaybolur, sonra cihazdan silinir. Testlerin çoğu tam olarak bunu kilitler.
 */
final class SecureAccessPortReaderTest extends TestCase
{
    /**
     * Cihazda ölçülen tek biçim (test1, commit check):
     * "secure-access-port { vlan all { examine-dhcp; } }".
     */
    public function test_vlan_korumalari_okunur(): void
    {
        $okunan = $this->read(
            '<secure-access-port>'
            .'<vlan><name>all</name><examine-dhcp/></vlan>'
            .'<vlan><name>v30</name><examine-dhcp/><arp-inspection/><ip-source-guard/></vlan>'
            .'</secure-access-port>'
        );

        $this->assertCount(2, $okunan['vlans']);
        $this->assertSame('all', $okunan['vlans'][0]['vlan_name']);
        $this->assertTrue($okunan['vlans'][0]['examine_dhcp']);
        $this->assertFalse($okunan['vlans'][0]['arp_inspection']);
        $this->assertSame([], $okunan['vlans'][0]['residual_xml'], 'Modellenen alan artığa düşmemeli.');

        $this->assertTrue($okunan['vlans'][1]['arp_inspection']);
        $this->assertTrue($okunan['vlans'][1]['ip_source_guard']);
    }

    /**
     * Güven durumu ÜÇ değerlidir: satır yok (cihazın varsayılanı),
     * dhcp-trusted ve no-dhcp-trusted. İkisi tek boolean'a sıkıştırılamaz —
     * "varsayılan" ile "açıkça güvenilmez" aynı şey değildir ve panel
     * ikincisini yazabiliyorsa birincisini de ayırt edebilmelidir.
     */
    public function test_dhcp_guveni_uc_durumludur(): void
    {
        $okunan = $this->read(
            '<secure-access-port>'
            .'<interface><name>ge-0/0/0.0</name><dhcp-trusted/></interface>'
            .'<interface><name>ge-0/0/1.0</name><no-dhcp-trusted/></interface>'
            .'<interface><name>ge-0/0/2.0</name></interface>'
            .'</secure-access-port>'
        );

        $this->assertTrue($okunan['interfaces'][0]['dhcp_trusted']);
        $this->assertFalse($okunan['interfaces'][1]['dhcp_trusted']);
        $this->assertNull($okunan['interfaces'][2]['dhcp_trusted']);
    }

    /**
     * Panelin tanımadığı satır (static-ip) ham hâliyle saklanır; yazma yolu
     * onu aynen geri basar.
     */
    public function test_taninmayan_satir_ham_haliyle_saklanir(): void
    {
        $okunan = $this->read(
            '<secure-access-port><interface><name>ge-0/0/3.0</name><dhcp-trusted/>'
            .'<static-ip><name>198.18.20.20</name><vlan>v30</vlan><mac>00:11:22:33:44:55</mac></static-ip>'
            .'</interface></secure-access-port>'
        );

        $this->assertSame(
            ['<static-ip><name>198.18.20.20</name><vlan>v30</vlan><mac>00:11:22:33:44:55</mac></static-ip>'],
            $okunan['interfaces'][0]['residual_xml'],
        );
        $this->assertTrue($okunan['interfaces'][0]['dhcp_trusted'], 'Tanınan alan yine okunmalı.');
    }

    /**
     * Sınırın iki XML biçimi de tanınır; hangisinin geldiği CİHAZDA
     * ÖLÇÜLMEDİ ve tek biçime bel bağlamak diğerini görünmez yapardı.
     */
    public function test_sinir_iki_bicimde_de_okunur(): void
    {
        $ic = $this->read(
            '<secure-access-port><interface><name>ge-0/0/4.0</name>'
            .'<mac-limit><limit>4</limit><action>drop</action></mac-limit>'
            .'</interface></secure-access-port>'
        );

        $this->assertSame(4, $ic['interfaces'][0]['mac_limit']);
        $this->assertSame('drop', $ic['interfaces'][0]['mac_limit_action']);
        $this->assertSame([], $ic['interfaces'][0]['residual_xml']);

        $duz = $this->read(
            '<secure-access-port><vlan><name>v30</name>'
            .'<mac-move-limit>5</mac-move-limit><action>shutdown</action>'
            .'</vlan></secure-access-port>'
        );

        $this->assertSame(5, $duz['vlans'][0]['mac_move_limit']);
        $this->assertSame('shutdown', $duz['vlans'][0]['mac_move_action']);
        $this->assertSame([], $duz['vlans'][0]['residual_xml'], 'Kardeş action da tanınmış sayılmalı.');
    }

    /**
     * ANLAŞILMAYAN sınır biçimi silinmez.
     *
     * Bu testin kilitlediği şey bir ayrıştırma inceliği değil, bir VERİ KAYBI
     * senaryosudur: panel "mac-limit" adını bilip içeriğini çözemezse, satırı
     * ne ekranda gösterir ne de artıkta korur — ilk düzenlemede cihazdan
     * silinirdi.
     */
    public function test_anlasilmayan_sinir_bicimi_korunur(): void
    {
        $okunan = $this->read(
            '<secure-access-port><interface><name>ge-0/0/5.0</name>'
            .'<mac-limit><uydurma-alan>4</uydurma-alan></mac-limit>'
            .'</interface></secure-access-port>'
        );

        $this->assertNull($okunan['interfaces'][0]['mac_limit']);
        $this->assertSame(
            ['<mac-limit><uydurma-alan>4</uydurma-alan></mac-limit>'],
            $okunan['interfaces'][0]['residual_xml'],
        );
    }

    /**
     * Tanınmayan bir EYLEM de aynı kurala tabidir: eylemi düşürüp sınırı
     * yazmak, cihazdaki koruma davranışını sessizce değiştirmek olurdu.
     */
    public function test_taninmayan_eylem_satiri_korunur(): void
    {
        $okunan = $this->read(
            '<secure-access-port><vlan><name>v40</name>'
            .'<mac-move-limit><limit>3</limit><action>uydurma</action></mac-move-limit>'
            .'</vlan></secure-access-port>'
        );

        $this->assertNull($okunan['vlans'][0]['mac_move_limit']);
        $this->assertSame(
            ['<mac-move-limit><limit>3</limit><action>uydurma</action></mac-move-limit>'],
            $okunan['vlans'][0]['residual_xml'],
        );
    }

    /**
     * allowed-mac köşeli parantezli bir listedir; Junos yinelenen elemanlarla
     * yazar. Sarmalayıcılı biçim de okunur.
     */
    public function test_izinli_mac_listesi_okunur(): void
    {
        $yinelenen = $this->read(
            '<secure-access-port><interface><name>ge-0/0/6.0</name>'
            .'<allowed-mac>00:11:22:33:44:55</allowed-mac>'
            .'<allowed-mac>00:11:22:33:44:66</allowed-mac>'
            .'</interface></secure-access-port>'
        );

        $this->assertSame(
            ['00:11:22:33:44:55', '00:11:22:33:44:66'],
            $yinelenen['interfaces'][0]['allowed_macs'],
        );
        $this->assertSame([], $yinelenen['interfaces'][0]['residual_xml']);

        $sarmalayici = $this->read(
            '<secure-access-port><interface><name>ge-0/0/7.0</name>'
            .'<allowed-mac><mac-list>00:11:22:33:44:77</mac-list></allowed-mac>'
            .'</interface></secure-access-port>'
        );

        $this->assertSame(['00:11:22:33:44:77'], $sarmalayici['interfaces'][0]['allowed_macs']);
    }

    public function test_dugum_yoksa_bos_doner(): void
    {
        $okunan = $this->read('');

        $this->assertSame([], $okunan['vlans']);
        $this->assertSame([], $okunan['interfaces']);
    }

    /**
     * @return array{vlans: list<array<string, mixed>>, interfaces: list<array<string, mixed>>}
     */
    private function read(string $body): array
    {
        $reply = '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="1">'
            .'<configuration><ethernet-switching-options>'.$body.'</ethernet-switching-options></configuration>'
            .'</rpc-reply>'."\n]]>]]>";

        $transport = new FakeNetconfTransport([$this->hello(), $reply]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (new SecureAccessPortReader)->secureAccessPort($session);
    }

    private function hello(): string
    {
        return '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability></capabilities></hello>'."\n]]>]]>";
    }
}
