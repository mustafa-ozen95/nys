<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\VoipReader;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * VoIP (ses) VLAN ayarının okunması.
 *
 * Ayar iki şema ailesinde iki ayrı kapsayıcının altında durur; okuyucu
 * ikisini de bulmak zorundadır çünkü envanterin göstermesi gereken şey
 * cihazda ne olduğudur.
 */
final class VoipReaderTest extends TestCase
{
    public function test_els_oncesi_kapsayici_okunur(): void
    {
        $interfaces = $this->read(
            '<ethernet-switching-options><voip>'
            .'<interface><name>ge-0/0/5.0</name><vlan>ses</vlan>'
            .'<forwarding-class>expedited-forwarding</forwarding-class></interface>'
            .'<interface><name>access-ports</name><vlan>ses</vlan></interface>'
            .'</voip></ethernet-switching-options>'
        );

        $this->assertCount(2, $interfaces);

        $this->assertSame('ge-0/0/5.0', $interfaces[0]['interface_name']);
        $this->assertSame('ses', $interfaces[0]['vlan_name']);
        $this->assertSame('expedited-forwarding', $interfaces[0]['forwarding_class']);

        $this->assertSame('access-ports', $interfaces[1]['interface_name']);
        $this->assertNull($interfaces[1]['forwarding_class']);
    }

    /**
     * ELS'te aynı ayar switch-options altındadır. Okuyucu profile bakmaz:
     * cihazda hangisi varsa o okunur.
     */
    public function test_els_kapsayicisi_da_okunur(): void
    {
        $interfaces = $this->read(
            '<switch-options><voip>'
            .'<interface><name>ge-0/0/1.0</name><vlan>voice</vlan></interface>'
            .'</voip></switch-options>'
        );

        $this->assertCount(1, $interfaces);
        $this->assertSame('ge-0/0/1.0', $interfaces[0]['interface_name']);
        $this->assertSame('voice', $interfaces[0]['vlan_name']);
    }

    /**
     * Panelin TANIMADIĞI satır sessizce düşmez: ham XML olarak saklanır ve
     * yazarken aynen geri basılır. Kaybolan tek satır, kimsenin fark
     * etmediği bir yapılandırma değişikliğidir.
     */
    public function test_taninmayan_satir_ham_olarak_korunur(): void
    {
        $interfaces = $this->read(
            '<ethernet-switching-options><voip>'
            .'<interface><name>ge-0/0/5.0</name><vlan>ses</vlan>'
            .'<uydurma-alan>deger</uydurma-alan></interface>'
            .'</voip></ethernet-switching-options>'
        );

        $this->assertSame(['<uydurma-alan>deger</uydurma-alan>'], $interfaces[0]['residual_xml']);
    }

    /**
     * Adsız düğüm cihazda olamaz; olursa envantere kimliksiz kayıt girer ve
     * yazma yolu onu geri yazamaz.
     */
    public function test_adsiz_dugum_atlanir(): void
    {
        $interfaces = $this->read(
            '<ethernet-switching-options><voip><interface><vlan>ses</vlan></interface></voip>'
            .'</ethernet-switching-options>'
        );

        $this->assertSame([], $interfaces);
    }

    public function test_voip_yoksa_liste_bostur(): void
    {
        $this->assertSame([], $this->read('<ethernet-switching-options/>'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $body): array
    {
        $reply = '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="1">'
            .'<configuration>'.$body.'</configuration></rpc-reply>'."\n]]>]]>";

        $transport = new FakeNetconfTransport([$this->hello(), $reply]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (new VoipReader)->voip($session);
    }

    private function hello(): string
    {
        return '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability>'
            ."</capabilities></hello>\n]]>]]>";
    }
}
