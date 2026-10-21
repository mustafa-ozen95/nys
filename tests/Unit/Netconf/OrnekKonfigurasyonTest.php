<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Enums\FirewallAction;
use App\Enums\IpFamily;
use App\Services\Netconf\BgpReader;
use App\Services\Netconf\FirewallReader;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\IpAddressReader;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\PolicyStatementReader;
use App\Services\Netconf\RouteReader;
use Illuminate\Support\Collection;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * Omurga ve kabin tipi yapılandırmalara karşı regresyon.
 *
 * Fixture'lar gerçek Junos çıktılarının yapısını taklit eden anonim
 * örneklerdir: omurga (EX4500) ve kabin switchi (EX2200) tipindeki yapılar
 * bir lab cihazının (test1) ÖZEL adayına yüklenip cihazın ürettiği XML
 * biçimiyle kaydedildi. Yani burada sınanan şey "bizim beklediğimiz XML"
 * değil, CİHAZIN yazdığı biçim (bkz. tests/Fixtures/netconf/BENIOKU.md).
 *
 * Bu dosyanın amacı tek tek alanları doğrulamak değil — onu her okuyucunun
 * kendi testi yapıyor. Buradaki soru şu: omurga ve kabin switchlerinde yaygın
 * bir yapı panelde SESSİZCE KAYBOLUYOR mu?
 */
final class OrnekKonfigurasyonTest extends TestCase
{
    /**
     * Politikalar: prefix-list-filter, çıplak prefix-list, route-filter
     * aralığı, community ekle/sil, next-hop self ve rakamla başlayan ad.
     */
    public function test_politika_yapilari_kayboluyor_mu(): void
    {
        $politikalar = collect($this->oku(new PolicyStatementReader, 'ornek-policy-options', 'statements'))
            ->keyBy('name');

        // Rakamla başlayan politika adı (100-hepsi) okunabilmeli.
        $this->assertTrue($politikalar->has('100-hepsi'));

        $musteri1 = $politikalar->get('prefixes-from-musteri1');
        $terimler = collect($musteri1['terms'])->keyBy('name');

        // "term clear" yalnızca "then" taşır; "from"u yoktur.
        $this->assertSame('topluluk-a', $terimler['clear']['community_delete']);

        // prefix-list-filter kendi sütununa ayrılır, koşullara karışmaz.
        $this->assertSame(['musteri1-PREFIXES'], $terimler['A']['match_prefix_lists']);

        $self = collect($politikalar->get('self-all')['terms'])->keyBy('name');

        // Çıplak "from prefix-list" ile "prefix-list-filter" AYRI şeylerdir.
        $this->assertContains('filtre-hizmeti-a', $this->duzlestir($self['topluluk-a']['match_conditions']));

        // route-filter prefix-length-range aralığı korunmalı.
        $this->assertNotEmpty($self['C']['match_conditions']);
        $this->assertSame(FirewallAction::Accept->value, (string) $self['C']['action']?->value);
    }

    /**
     * Devre dışı bırakılmış politika ETKİN görünmemeli.
     */
    public function test_devre_disi_politika_isaretlenir(): void
    {
        $politikalar = collect($this->oku(new PolicyStatementReader, 'ornek-policy-options', 'statements'))
            ->keyBy('name');

        $this->assertTrue($politikalar->get('no-route')['is_inactive']);
        $this->assertFalse($politikalar->get('100-hepsi')['is_inactive']);
    }

    /**
     * Firewall: ttl, packet-length, icmp-code, tcp-initial, is-fragment,
     * count, policer, routing-instance (FBF) ve EYLEMSİZ terim.
     */
    public function test_firewall_yapilari_kayboluyor_mu(): void
    {
        $filtreler = collect($this->oku(new FirewallReader, 'ornek-firewall', 'filters'))->keyBy('name');

        $udpFiltre = collect($filtreler->get('koru')['terms'])->keyBy('name')['udp-filtre'];
        $this->assertSame(['100-120'], $udpFiltre['packet_lengths']);
        $this->assertSame(['10-20'], $udpFiltre['ttls']);
        $this->assertSame('udp-filtre-limit', $udpFiltre['policer']);

        $icmp = collect($filtreler->get('koru1')['terms'])->keyBy('name')['icmp-stop'];
        $this->assertSame(['host-unreachable'], $icmp['icmp_codes']);
        $this->assertSame('icmp-stop', $icmp['count_name']);

        $smtp = collect($filtreler->get('filtre-b-input')['terms'])->keyBy('name')['stop-smtp'];
        $this->assertTrue($smtp['is_tcp_initial']);

        $parca = collect($filtreler->get('out-filter')['terms'])->keyBy('name')['fragment-drop'];
        $this->assertTrue($parca['is_fragment']);

        // Filtre tabanlı yönlendirme: terim trafiği başka bir tabloya atar.
        $fbf = collect($filtreler->get('siniflandir-a')['terms'])->keyBy('name')['tablo-a-musteri'];
        $this->assertSame('tablo-a', $fbf['routing_instance']);

        /*
         * "from" var ama "then" YOK; Junos bu biçimi kabul ediyor.
         * Eylemi null okunmalı; terim düşmemeli.
         */
        $meFilter = collect($filtreler->get('ORNEK-ME_FILTER')['terms'])->keyBy('name')['kabul'];
        $this->assertNull($meFilter['action']);
        $this->assertNotEmpty($meFilter['source_addresses']);

        // Devre dışı terim işaretlenmeli.
        $this->assertTrue(collect($filtreler->get('koru1')['terms'])->keyBy('name')['allow']['is_inactive']);
    }

    /**
     * IPv6 filtresi ayrı bir aile altındadır ve düşmemeli.
     */
    public function test_ipv6_filtresi_okunur(): void
    {
        $filtreler = collect($this->oku(new FirewallReader, 'ornek-firewall', 'filters'));
        $v6 = $filtreler->firstWhere('name', 'V6-RE_FILTER');

        $this->assertNotNull($v6);
        $this->assertSame('inet6', $v6['family']->value);
    }

    /**
     * IPv6 statik route'lar "routing-options rib inet6.0 static" altındadır,
     * düz "routing-options static" altında DEĞİL.
     */
    public function test_ipv6_route_rib_altindan_okunur(): void
    {
        $routes = collect($this->oku(new RouteReader, 'ornek-routing-options', 'configuredStatic'))
            ->keyBy(static fn (array $route): string => $route['destination'].'/'.$route['prefix_length']);

        $v6 = $routes->get('2001:db8:f000::/36');
        $this->assertNotNull($v6, 'rib inet6.0 altındaki route okunmadı');
        $this->assertSame('inet6.0', $v6['rib']);

        // "discard" bir sonraki atlama değil, yolun davranışıdır.
        $this->assertNotNull($routes->get('198.51.100.94/32'));
    }

    /**
     * Devre dışı BGP grubu ETKİN görünmemeli.
     */
    public function test_devre_disi_bgp_grubu_isaretlenir(): void
    {
        $gruplar = collect($this->oku(new BgpReader, 'ornek-protocols', 'groups'))->keyBy('name');

        $this->assertTrue($gruplar->get('router')['is_inactive']);
        $this->assertFalse($gruplar->get('musteri1')['is_inactive']);

        // IPv6 ailesi ve prefix-limit teardown/idle-timeout korunmalı.
        $v6 = $gruplar->get('musteri1-v6');
        $aile = collect($v6['families'])->firstWhere('family.value', 'inet6');
        $this->assertNotNull($aile);
        $this->assertSame(50, $aile['prefix_limit_maximum']);
        $this->assertTrue($aile['teardown']);
    }

    /**
     * Omurga tipi arayüz biçimleri: tek birimde ÇOK adres, IPv4 ile IPv6 bir
     * arada, /128 tekil adres ve L3 arayüzü (vlan.N) üstündeki adresler.
     *
     * Panel bunları düşürseydi müşteri adresleri envanterde hiç görünmezdi.
     */
    public function test_arayuz_adresleri_kayboluyor_mu(): void
    {
        $adresler = collect($this->oku(new IpAddressReader, 'ornek-interfaces', 'addresses')['addresses']);

        $cok = $adresler->where('interface_name', 'ge-0/1/0.0');

        // Aynı birimde iki IPv4 ve bir IPv6.
        $this->assertSame(['198.51.100.129', '198.18.5.109'], $cok->where('family', IpFamily::Inet)->pluck('address')->all());
        $this->assertSame(['2001:db8:0:120::1'], $cok->where('family', IpFamily::Inet6)->pluck('address')->all());

        // /128 tekil IPv6 adresi.
        $tekil = $adresler->firstWhere('address', '2001:db8:0:707::1');
        $this->assertNotNull($tekil);
        $this->assertSame(128, $tekil['prefix_length']);

        // ae arayüzünün kendi adresleri.
        $ae = $adresler->where('interface_name', 'ae7.0');
        $this->assertContains('198.51.100.142', $ae->pluck('address')->all());
        $this->assertContains('fd00:abcd:0002::2', $ae->pluck('address')->all());

        // L3 arayüzü (vlan.N) üstündeki adresler.
        $l3 = $adresler->where('interface_name', 'vlan.910');
        $this->assertContains('203.0.113.134', $l3->pluck('address')->all());
        $this->assertContains('fd00:abcd:000b::2', $l3->pluck('address')->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function oku(object $reader, string $fixture, string $method): array
    {
        $transport = new FakeNetconfTransport([$this->hello(), $this->fixture($fixture)]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return $reader->{$method}($session);
    }

    /**
     * İç içe koşul dizisini tek düzeye indirir.
     *
     * @param  array<string, mixed>  $conditions
     * @return list<string>
     */
    private function duzlestir(array $conditions): array
    {
        return Collection::make($conditions)
            ->flatten()
            ->map(static fn ($value): string => is_scalar($value) ? (string) $value : '')
            ->all();
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
