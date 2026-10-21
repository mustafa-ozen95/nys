<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Enums\AdminStatus;
use App\Enums\PortMode;
use App\Enums\PortStatus;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\PortReader;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * Sabit değerler jn32-test'ten (EX4200, JunOS 12.3R12.4) alınan gerçek
 * çıktının sadeleştirilmiş hâlidir.
 */
final class PortReaderTest extends TestCase
{
    public function test_port_alanlari_cozumlenir(): void
    {
        $ports = $this->read();

        $this->assertSame('ge-0/0/0', $ports[0]['name']);
        $this->assertSame(525, $ports[0]['if_index']);
        $this->assertSame('uplink', $ports[0]['description']);
        $this->assertSame(AdminStatus::Up, $ports[0]['admin_status']);
        $this->assertSame(PortStatus::Up, $ports[0]['oper_status']);
        $this->assertTrue($ports[0]['is_autonegotiation_enabled']);
        $this->assertSame(PortMode::Access, $ports[0]['port_mode']);
        $this->assertSame('V00', $ports[0]['untagged']);
    }

    /**
     * Yapılandırmadaki "disable" satırı, cihazın bildirdiği duruma bakılmadan
     * portu kapalı sayar.
     */
    public function test_disable_satiri_portu_kapali_gosterir(): void
    {
        $ports = $this->read();

        $this->assertSame('ge-0/0/1', $ports[1]['name']);
        $this->assertSame(AdminStatus::Down, $ports[1]['admin_status']);
        $this->assertSame(PortMode::Trunk, $ports[1]['port_mode']);
        $this->assertSame(['V00', 'V01'], $ports[1]['vlans']);
        $this->assertNull($ports[1]['untagged']);
    }

    public function test_yapilandirilmis_hiz_okunur(): void
    {
        $ports = $this->read();

        $this->assertSame('100', $ports[2]['speed_config']);
        $this->assertFalse($ports[2]['is_autonegotiation_enabled']);
    }

    /**
     * Portların kaynağı donanımdır: yapılandırmada tanımlı ama cihazda
     * bulunmayan uplink modülü portları envantere girmez.
     */
    public function test_cihazda_olmayan_yapilandirilmis_port_atlanir(): void
    {
        $names = array_column($this->read(), 'name');

        $this->assertNotContains('xe-0/1/0', $names);
        $this->assertNotContains('me0', $names);
    }

    public function test_vlan_etiketleri_ve_l3_arayuzu_okunur(): void
    {
        $transport = new FakeNetconfTransport([$this->hello(), $this->interfaceStatus(), $this->configuration()]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        $vlans = (new PortReader)->ports($session)['vlans'];

        $this->assertSame(100, $vlans['V00']['tag']);
        $this->assertSame('vlan.100', $vlans['V00']['l3_interface']);
        $this->assertNull($vlans['V01']['l3_interface']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * Üyelik cihazda İKİ YOLDAN bildirilebilir ve cihaz ikisini tek üyelik
     * sayar — farklı VLAN iki yoldan bildirilen access portu "has more than
     * one vlan member" diyerek reddediyor (test1 üzerinde ölçüldü).
     *
     * Panel yalnızca arayüz tarafını okuyordu; üyeliği vlans tarafında
     * tutan kabin switchlerinde o portlar ÜYELİKSİZ ve MODSUZ görünüyordu.
     * Panelden VLAN vermek de cihaz reddine düşerdi.
     */
    public function test_vlans_altinda_bildirilen_uyelik_de_okunur(): void
    {
        $port = collect($this->read())->firstWhere('name', 'ge-0/0/3');

        $this->assertSame('V32', $port['untagged']);
        $this->assertSame(PortMode::Access, $port['port_mode']);
    }

    /**
     * Etiket ZORUNLU DEĞİLDİR: Junos "vlans <ad>" satırını vlan-id olmadan
     * kabul ediyor (test1'de tek başına, l3-interface'li ve port üyeli
     * hâlleri commit check ile doğrulandı).
     *
     * Okuyucu etiketi şart koşmaz; koşsaydı etiketsiz VLAN envanterde HİÇ
     * görünmez, üstündeki adresler sahipsiz kalırdı.
     */
    public function test_etiketsiz_vlan_da_okunur(): void
    {
        $vlans = $this->readAll()['vlans'];

        $this->assertArrayHasKey('V27', $vlans);
        $this->assertNull($vlans['V27']['tag']);
        $this->assertSame('vlan.28', $vlans['V27']['l3_interface']);
    }

    private function read(): array
    {
        return $this->readAll()['ports'];
    }

    /**
     * @return array{ports: list<array<string, mixed>>, vlans: array<string, array<string, mixed>>, device_count: ?int}
     */
    private function readAll(): array
    {
        $transport = new FakeNetconfTransport([$this->hello(), $this->interfaceStatus(), $this->configuration()]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (new PortReader)->ports($session);
    }

    private function interfaceStatus(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><interface-information>'
            .'<physical-interface><name>ge-0/0/0</name><admin-status>up</admin-status>'
            .'<oper-status>up</oper-status><snmp-index>525</snmp-index></physical-interface>'
            .'<physical-interface><name>ge-0/0/1</name><admin-status>up</admin-status>'
            .'<oper-status>down</oper-status><snmp-index>526</snmp-index></physical-interface>'
            .'<physical-interface><name>ge-0/0/2</name><admin-status>up</admin-status>'
            .'<oper-status>down</oper-status><snmp-index>527</snmp-index></physical-interface>'
            .'<physical-interface><name>ge-0/0/3</name><admin-status>up</admin-status>'
            .'<oper-status>up</oper-status><snmp-index>528</snmp-index></physical-interface>'
            .'<physical-interface><name>me0</name><admin-status>up</admin-status>'
            .'<oper-status>up</oper-status><snmp-index>1</snmp-index></physical-interface>'
            .'</interface-information></rpc-reply>'."\n]]>]]>";
    }

    private function configuration(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration><interfaces>'
            .'<interface><name>ge-0/0/0</name><description>uplink</description>'
            .'<ether-options><speed><auto-negotiation/></speed></ether-options>'
            .'<unit><name>0</name><family><ethernet-switching><vlan><members>V00</members></vlan>'
            .'</ethernet-switching></family></unit></interface>'
            .'<interface><name>ge-0/0/1</name><disable/>'
            .'<unit><name>0</name><family><ethernet-switching><port-mode>trunk</port-mode>'
            .'<vlan><members>V00</members><members>V01</members></vlan>'
            .'</ethernet-switching></family></unit></interface>'
            /*
             * Junos "interface-mode"u da kabul ediyor ve hangisi yazıldıysa
             * onu saklıyor (jn32-test, 12.3R12.4 ile ölçüldü). Okuyucu
             * ikisini de tanımak zorunda; yalnızca "port-mode" arandığında
             * bu port MODSUZ görünüyordu.
             */
            .'<interface><name>ge-0/0/2</name>'
            .'<ether-options><speed><ethernet-100m/></speed>'
            .'<link-mode>half-duplex</link-mode></ether-options>'
            // native-vlan-id fiziksel arayüz altındadır, unit altında değil.
            .'<native-vlan-id>100</native-vlan-id>'
            .'<unit><name>0</name><family><ethernet-switching>'
            .'<interface-mode>trunk</interface-mode>'
            .'<vlan><members>V00</members><members>V01</members></vlan>'
            .'</ethernet-switching></family></unit></interface>'
            /*
             * Kabin switchlerinde yaygın biçim: portun kendi stanza'sında
             * üyelik YOK, üyelik "vlans <ad> interface <port>.0" satırında
             * duruyor.
             */
            .'<interface><name>ge-0/0/3</name><unit><name>0</name>'
            .'<family><ethernet-switching/></family></unit></interface>'
            // Uplink modülü yapılandırmada var ama cihazda yok.
            .'<interface><name>xe-0/1/0</name></interface>'
            .'</interfaces><vlans>'
            .'<vlan><name>V00</name><vlan-id>100</vlan-id><l3-interface>vlan.100</l3-interface></vlan>'
            .'<vlan><name>V01</name><vlan-id>101</vlan-id></vlan>'
            /*
             * Etiketsiz VLAN: vlan-id yok ama L3 arayüzü ve üstünde adres
             * olabilir.
             */
            .'<vlan><name>V27</name><l3-interface>vlan.28</l3-interface></vlan>'
            .'<vlan><name>V32</name><vlan-id>132</vlan-id>'
            .'<interface><name>ge-0/0/3.0</name></interface></vlan>'
            .'</vlans></configuration></rpc-reply>'."\n]]>]]>";
    }

    /**
     * Junos aynı ayarı iki adla kabul ediyor: "port-mode" (klasik) ve
     * "interface-mode" (ELS'in adı; 12.3R12.4 de kabul ediyor — jn32-test
     * üzerinde ölçüldü). CLI'dan "interface-mode" ile ayarlanmış bir port
     * panelde MODSUZ görünüyordu: trunk portu access sanılıyor, VLAN
     * üyeliği yanlış yorumlanıyordu.
     */
    public function test_interface_mode_de_okunur(): void
    {
        $port = collect($this->read())->firstWhere('name', 'ge-0/0/2');

        $this->assertSame(PortMode::Trunk, $port['port_mode']);
        $this->assertSame(['V01'], $port['vlans']);
        $this->assertSame('V00', $port['untagged']);
    }

    /**
     * Duplex CİHAZDAN OKUNMALI.
     *
     * "ether-options/link-mode" tek dizelik bir yol olarak sorgulanamaz:
     * local-name() asla "/" içermediği için sorgu hiçbir zaman eşleşmez ve
     * duplex_config her zaman null kalır. Sonuç: panel "Otomatik" gösterir,
     * bir sonraki kayıt cihazdaki half-duplex ayarını kullanıcı hiç
     * dokunmadan siler.
     */
    public function test_ayarlanmis_duplex_okunur(): void
    {
        $port = collect($this->read())->firstWhere('name', 'ge-0/0/2');

        $this->assertSame('half', $port['duplex_config']);
    }

    private function hello(): string
    {
        return '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability></capabilities></hello>'."\n]]>]]>";
    }
}
