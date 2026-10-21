<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\RouteReader;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * Statik route'un TAMAMI okunur ve modellenir.
 *
 * Yalnız hedef + tek atlama değil, geri kalan her şey (ECMP, preference,
 * tag, retain, IPv6...) modellenir; hiçbiri düzenlemeyi kapatmaz.
 * Modellenmeyen uzun kuyruk ham XML olarak korunur ve geri yazılırken aynen
 * basılır.
 */
final class RouteManageabilityTest extends TestCase
{
    public function test_tek_atlamali_sade_route_yonetilebilir(): void
    {
        $route = $this->read('<route><name>10.1.0.0/16</name><next-hop>198.18.8.37</next-hop></route>')[0];

        $this->assertNull($route['unmanageable_reason']);
        $this->assertSame(['198.18.8.37'], $route['next_hops']);
    }

    public function test_discard_ve_reject_yonetilebilir(): void
    {
        $routes = $this->read(
            '<route><name>10.2.0.0/16</name><discard/></route>'
            .'<route><name>10.3.0.0/16</name><reject/></route>'
        );

        $this->assertNull($routes[0]['unmanageable_reason']);
        $this->assertNull($routes[1]['unmanageable_reason']);
    }

    /**
     * ECMP YÖNETİLEBİLİR: bütün atlamalar okunur ve geri yazılabilir;
     * yedekli çıkışı olan bir route da panelden düzenlenir.
     */
    public function test_ecmp_route_yonetilebilir(): void
    {
        $route = $this->read(
            '<route><name>0.0.0.0/0</name>'
            .'<next-hop>198.18.8.37</next-hop><next-hop>198.18.8.38</next-hop></route>'
        )[0];

        $this->assertSame(['198.18.8.37', '198.18.8.38'], $route['next_hops']);
        $this->assertNull($route['unmanageable_reason']);
        $this->assertSame([], $route['residual_xml']);
    }

    /**
     * preference / metric / tag ve boş seçenekler artık modelleniyor.
     */
    public function test_preference_metric_tag_ve_secenekler_okunur(): void
    {
        $route = $this->read(
            '<route><name>0.0.0.0/0</name><next-hop>198.18.8.37</next-hop>'
            .'<preference>250</preference><metric>7</metric><tag>4242</tag>'
            .'<no-readvertise/><retain/></route>'
        )[0];

        $this->assertNull($route['unmanageable_reason']);
        $this->assertSame(250, $route['preference']);
        $this->assertSame(7, $route['metric']);
        $this->assertSame(4242, $route['tag']);
        $this->assertEqualsCanonicalizing(['retain', 'no-readvertise'], $route['flags']);
        $this->assertSame([], $route['residual_xml'], 'Modellenen alanlar artığa düşmemeli.');
    }

    /**
     * Yedekli çıkışın standart biçimi: birincil atlama + kendi preference'ı
     * olan qualified-next-hop. Okunmadığı sürece panel yedeği ne
     * gösterebiliyor ne de koruyabiliyordu.
     */
    public function test_qualified_next_hop_okunur(): void
    {
        $route = $this->read(
            '<route><name>0.0.0.0/0</name><next-hop>198.18.8.37</next-hop>'
            .'<qualified-next-hop><name>198.18.8.38</name><preference>250</preference>'
            .'<metric>5</metric></qualified-next-hop></route>'
        )[0];

        $this->assertSame([[
            'name' => '198.18.8.38',
            'preference' => 250,
            'metric' => 5,
            'interface' => null,
        ]], $route['qualified_next_hops']);
    }

    /**
     * IPv6 statik route cihazda "rib inet6.0" altındadır ve artık hem okunur
     * hem yazılır.
     */
    public function test_ipv6_statik_route_okunur(): void
    {
        $reply = '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration>'
            .'<routing-options><rib><name>inet6.0</name><static>'
            .'<route><name>2001:db8::/32</name><next-hop>2001:db8::1</next-hop></route>'
            .'</static></rib></routing-options>'
            ."</configuration></rpc-reply>\n]]>]]>";

        $route = $this->readReply($reply)[0];

        $this->assertSame('inet6.0', $route['rib']);
        $this->assertSame('2001:db8::', $route['destination']);
        $this->assertNull($route['unmanageable_reason']);
    }

    /**
     * Panelin TANIMADIĞI satır kaybolmaz: ham XML olarak saklanır ve geri
     * yazılırken aynen basılır. "Anlamadığını sessizce sil" hatasının kökten
     * çözümü budur.
     */
    public function test_tanimadigi_satir_ham_haliyle_korunur(): void
    {
        $route = $this->read(
            '<route><name>0.0.0.0/0</name><next-hop>198.18.8.37</next-hop>'
            .'<uydurma-alan>x</uydurma-alan></route>'
        )[0];

        $this->assertNull($route['unmanageable_reason'], 'Artık salt okunur yapılmıyor.');
        $this->assertSame(['<uydurma-alan>x</uydurma-alan>'], $route['residual_xml']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $routes): array
    {
        return $this->readReply(
            '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration>'
            .'<routing-options><static>'.$routes.'</static></routing-options>'
            ."</configuration></rpc-reply>\n]]>]]>"
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readReply(string $reply): array
    {
        $transport = new FakeNetconfTransport([$this->hello(), $reply]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (new RouteReader)->configuredStatic($session);
    }

    private function hello(): string
    {
        return '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability>'
            ."</capabilities></hello>\n]]>]]>";
    }
}
