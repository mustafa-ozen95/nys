<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Enums\BgpGracefulRestart;
use App\Enums\BgpGroupType;
use App\Enums\BgpNlri;
use App\Enums\BgpState;
use App\Enums\IpFamily;
use App\Services\Netconf\BgpReader;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeNetconfTransport;

/**
 * Ayrıştırıcı, cihazdan alınan GERÇEK XML ile sınanır.
 *
 * bgp-configuration.xml jn32-test'in kanonik çıktısıdır: her varyant (ttl'li
 * multihop, passive, çok politikalı import, local-as, unicast/any, eşikli ve
 * eşiksiz teardown, dakikalı idle-timeout) cihaza yazdırılıp geri okunarak
 * üretildi. bgp-configuration-backbone.xml omurga tipi bir yapılandırmayı
 * taklit eden anonim örnektir; "idle-timeout forever" ile log-updown'ı
 * oradan gelir.
 */
final class BgpReaderTest extends TestCase
{
    public function test_grup_alanlari_cozumlenir(): void
    {
        $groups = $this->okuGruplar($this->fixture('bgp-configuration'));

        $this->assertCount(2, $groups);

        $ic = $groups[0];
        $this->assertSame('ic', $ic['name']);
        $this->assertSame(BgpGroupType::Internal, $ic['type']);
        $this->assertSame('198.18.8.38', $ic['local_address']);
        $this->assertTrue($ic['passive']);
        $this->assertFalse($ic['multihop']);
    }

    public function test_multihop_ttl_ile_okunur(): void
    {
        $dis = $this->okuGruplar($this->fixture('bgp-configuration'))[1];

        $this->assertTrue($dis['multihop']);
        $this->assertSame(5, $dis['multihop_ttl']);
    }

    /**
     * Junos ttl'siz multihop'u BOŞ eleman olarak yazar; metnine bakan bir
     * ayrıştırıcı bunu kapalı sanırdı.
     */
    public function test_ttlsiz_multihop_acik_sayilir(): void
    {
        $group = $this->okuGruplar($this->reply('<protocols><bgp><group>'
            .'<name>x</name><type>external</type><multihop></multihop>'
            .'</group></bgp></protocols>'))[0];

        $this->assertTrue($group['multihop']);
        $this->assertNull($group['multihop_ttl']);
    }

    /**
     * "deactivate" ÖZNİTELİKTE gelir; gövdede bir iz bırakmaz.
     *
     * Okunmazsa devre dışı grup panelde ETKİN görünür: operatör kurulu
     * sandığı bir oturumun aslında çalışmadığını göremez.
     */
    public function test_devre_disi_grup_ve_komsu_okunur(): void
    {
        $group = $this->okuGruplar($this->reply('<protocols><bgp>'
            .'<group inactive="inactive"><name>router</name><type>internal</type>'
            .'<neighbor inactive="inactive"><name>198.51.100.141</name></neighbor>'
            .'</group></bgp></protocols>'))[0];

        $this->assertTrue($group['is_inactive']);
        $this->assertTrue($group['neighbors'][0]['is_inactive']);
    }

    public function test_etkin_grup_devre_disi_isaretlenmez(): void
    {
        $group = $this->okuGruplar($this->reply('<protocols><bgp><group>'
            .'<name>x</name><type>external</type>'
            .'<neighbor><name>198.51.100.134</name></neighbor>'
            .'</group></bgp></protocols>'))[0];

        $this->assertFalse($group['is_inactive']);
        $this->assertFalse($group['neighbors'][0]['is_inactive']);
    }

    public function test_birden_fazla_import_politikasi_sirasiyla_okunur(): void
    {
        $dis = $this->okuGruplar($this->fixture('bgp-configuration'))[1];

        $this->assertSame(['p1', 'p2'], $dis['import_policies']);
        $this->assertSame(['e1'], $dis['export_policies']);
    }

    public function test_local_as_ve_peer_as_ayri_okunur(): void
    {
        $dis = $this->okuGruplar($this->fixture('bgp-configuration'))[1];

        $this->assertSame('65001', $dis['peer_asn']);
        $this->assertSame('65009', $dis['local_asn']);
    }

    /**
     * Prefix sınırı "any" ya da "unicast" altında durabilir; ikisi de aynı
     * anlama gelir ve ayrıştırıcı alt eleman adına bakmamalıdır.
     */
    public function test_prefix_siniri_unicast_ve_any_altinda_bulunur(): void
    {
        $dis = $this->okuGruplar($this->fixture('bgp-configuration'))[1];

        $this->assertCount(2, $dis['families']);

        [$inet, $inet6] = $dis['families'];

        $this->assertSame(IpFamily::Inet, $inet['family']);
        // Cihazda "unicast" yazıyordu; geri yazarken "any"ye dönüşmemeli.
        $this->assertSame(BgpNlri::Unicast, $inet['nlri']);
        $this->assertSame(100, $inet['prefix_limit_maximum']);
        $this->assertTrue($inet['teardown']);
        $this->assertSame(80, $inet['teardown_threshold']);
        $this->assertSame(30, $inet['idle_timeout_minutes']);
        $this->assertFalse($inet['idle_timeout_forever']);

        $this->assertSame(IpFamily::Inet6, $inet6['family']);
        $this->assertSame(BgpNlri::Any, $inet6['nlri']);
        $this->assertSame(50, $inet6['prefix_limit_maximum']);
        $this->assertTrue($inet6['teardown']);
        $this->assertNull($inet6['teardown_threshold']);
    }

    /**
     * Omurga örneğindeki hâli: "idle-timeout forever" BOŞ eleman olarak gelir ve
     * dakikalı hâlden yalnızca alt elemanın yokluğuyla ayrılır.
     */
    public function test_idle_timeout_forever_dakikali_halden_ayrilir(): void
    {
        $groups = $this->okuGruplar($this->fixture('bgp-configuration-backbone'));

        $v6 = collect($groups)->firstWhere('name', 'musteri1-v6');

        $this->assertNotNull($v6);
        $this->assertTrue($v6['families'][0]['idle_timeout_forever']);
        $this->assertNull($v6['families'][0]['idle_timeout_minutes']);
    }

    public function test_omurga_grubu_cozumlenir(): void
    {
        $groups = $this->okuGruplar($this->fixture('bgp-configuration-backbone'));

        $musteri1 = collect($groups)->firstWhere('name', 'musteri1');

        $this->assertNotNull($musteri1);
        $this->assertSame(BgpGroupType::External, $musteri1['type']);
        $this->assertSame('64497', $musteri1['peer_asn']);
        $this->assertSame('198.51.100.133', $musteri1['local_address']);
        $this->assertTrue($musteri1['log_updown']);
        $this->assertTrue($musteri1['multihop']);
        $this->assertSame(['prefixes-from-musteri1'], $musteri1['import_policies']);
        $this->assertSame(['only-default'], $musteri1['export_policies']);
        $this->assertSame(200, $musteri1['families'][0]['prefix_limit_maximum']);
        $this->assertSame('198.51.100.134', $musteri1['neighbors'][0]['address']);
    }

    public function test_komsu_kendi_degerleriyle_okunur(): void
    {
        $dis = $this->okuGruplar($this->fixture('bgp-configuration'))[1];

        $this->assertCount(1, $dis['neighbors']);
        $this->assertSame('198.18.8.37', $dis['neighbors'][0]['address']);
        $this->assertSame('dis komsu', $dis['neighbors'][0]['description']);
        $this->assertSame('65002', $dis['neighbors'][0]['peer_asn']);
        $this->assertSame('198.18.8.38', $dis['neighbors'][0]['local_address']);
    }

    public function test_bgp_yoksa_bos_doner(): void
    {
        $this->assertSame([], $this->okuGruplar($this->reply('<protocols/>')));
    }

    /**
     * Oturum durumu: iki lab cihazı (jn32-test, jn33-test) arasındaki
     * oturumun çıktısı biçiminde.
     */
    public function test_oturum_durumu_okunur(): void
    {
        $states = $this->states($this->fixture('bgp-summary-information'));

        $this->assertArrayHasKey('198.18.8.37', $states);

        $peer = $states['198.18.8.37'];
        $this->assertSame(BgpState::Established, $peer['state']);
        $this->assertSame(0, $peer['flap_count']);
        $this->assertSame(89, $peer['state_changed_seconds']);
        $this->assertSame(0, $peer['received_prefixes']);
    }

    /**
     * Oturum kurulmamışken cihaz durumu ad olarak yazar ve prefix sayaçları
     * hiç bulunmaz; sayaçların sıfır sanılmaması gerekir.
     */
    public function test_kurulmamis_oturumun_sayaclari_bos_kalir(): void
    {
        $states = $this->states($this->reply(
            '<bgp-information><bgp-peer><peer-address>10.0.0.9</peer-address>'
            .'<peer-state>Connect</peer-state><flap-count>3</flap-count></bgp-peer></bgp-information>',
            wrapConfiguration: false,
        ));

        $this->assertSame(BgpState::Connect, $states['10.0.0.9']['state']);
        $this->assertSame(3, $states['10.0.0.9']['flap_count']);
        $this->assertNull($states['10.0.0.9']['received_prefixes']);
        $this->assertNull($states['10.0.0.9']['state_changed_seconds']);
    }

    /**
     * Cihaz komşu adresini kaynak portla birlikte de verebilir.
     */
    public function test_adresin_yanindaki_port_atilir(): void
    {
        $states = $this->states($this->reply(
            '<bgp-information><bgp-peer><peer-address>198.18.8.37+179</peer-address>'
            .'<peer-state>Established</peer-state></bgp-peer></bgp-information>',
            wrapConfiguration: false,
        ));

        $this->assertArrayHasKey('198.18.8.37', $states);
    }

    public function test_as_numarasi_okunur(): void
    {
        $transport = new FakeNetconfTransport([$this->hello(), $this->reply(
            '<routing-options><autonomous-system><as-number>65000</as-number></autonomous-system></routing-options>'
        )]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        $this->assertSame('65000', (new BgpReader)->autonomousSystem($session));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function okuGruplar(string $reply): array
    {
        return (new BgpReader)->groups($this->session($reply));
    }

    /**
     * İleri ayarlar okunmazsa güncelleme döngüsü onları SESSİZCE siler:
     * "önce yönetilen alanları sil, sonra yaz" akışında panel yalnızca
     * bildiği alanları geri yazar.
     */
    public function test_ileri_grup_ayarlari_okunur(): void
    {
        $group = $this->okuGruplar($this->reply('<protocols><bgp><group>'
            .'<name>transit</name><type>external</type>'
            .'<authentication-key>$9$gizli</authentication-key>'
            .'<hold-time>30</hold-time>'
            .'<cluster>10.0.0.1</cluster>'
            .'<remove-private/><as-override/><advertise-inactive/><multipath/><damping/>'
            .'<out-delay>15</out-delay>'
            .'<bfd-liveness-detection><minimum-interval>300</minimum-interval></bfd-liveness-detection>'
            .'<allow>10.9.0.0/24</allow><allow>10.9.1.0/24</allow>'
            .'</group></bgp></protocols>'))[0];

        $this->assertSame('$9$gizli', $group['authentication_key']);
        $this->assertSame(30, $group['hold_time']);
        $this->assertSame('10.0.0.1', $group['cluster']);
        $this->assertTrue($group['remove_private']);
        $this->assertTrue($group['as_override']);
        $this->assertTrue($group['advertise_inactive']);
        $this->assertTrue($group['multipath']);
        $this->assertTrue($group['damping']);
        $this->assertSame(15, $group['out_delay']);
        $this->assertSame(300, $group['bfd_minimum_interval']);
        $this->assertSame(['10.9.0.0/24', '10.9.1.0/24'], $group['allow_prefixes']);
    }

    /**
     * Satırın hiç olmaması ile "disable" aynı şey değildir; ikisi tek bir
     * boolean'a sıkıştırılırsa cihazın genel ayarı sessizce ezilirdi.
     */
    public function test_graceful_restart_uc_durumu_ayrilir(): void
    {
        $oku = fn (string $body): BgpGracefulRestart => $this->okuGruplar($this->reply(
            '<protocols><bgp><group><name>x</name><type>external</type>'.$body.'</group></bgp></protocols>'
        ))[0]['graceful_restart'];

        $this->assertSame(BgpGracefulRestart::Inherit, $oku(''));
        $this->assertSame(BgpGracefulRestart::Enabled, $oku('<graceful-restart/>'));
        $this->assertSame(BgpGracefulRestart::Disabled, $oku('<graceful-restart><disable/></graceful-restart>'));
    }

    public function test_komsu_seviyesindeki_ayarlar_okunur(): void
    {
        $neighbor = $this->okuGruplar($this->reply('<protocols><bgp><group>'
            .'<name>transit</name><type>external</type><neighbor>'
            .'<name>198.51.100.134</name>'
            .'<authentication-key>$9$komsu</authentication-key>'
            .'<hold-time>45</hold-time><passive/>'
            .'<multihop><ttl>2</ttl></multihop>'
            .'<import>gelen</import><export>giden</export>'
            .'</neighbor></group></bgp></protocols>'))[0]['neighbors'][0];

        $this->assertSame('$9$komsu', $neighbor['authentication_key']);
        $this->assertSame(45, $neighbor['hold_time']);
        $this->assertTrue($neighbor['passive']);
        $this->assertTrue($neighbor['multihop']);
        $this->assertSame(2, $neighbor['multihop_ttl']);
        $this->assertSame(['gelen'], $neighbor['import_policies']);
        $this->assertSame(['giden'], $neighbor['export_policies']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function states(string $reply): array
    {
        return (new BgpReader)->neighborStates($this->session($reply));
    }

    private function session(string $reply): NetconfSession
    {
        $transport = new FakeNetconfTransport([$this->hello(), $reply]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return $session;
    }

    private function reply(string $body, bool $wrapConfiguration = true): string
    {
        $inner = $wrapConfiguration ? '<configuration>'.$body.'</configuration>' : $body;

        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="1">'
            .$inner.'</rpc-reply>'."\n]]>]]>";
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
