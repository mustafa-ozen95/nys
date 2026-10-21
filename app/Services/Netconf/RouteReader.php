<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\IpFamily;
use App\Enums\RouteFlag;
use App\Enums\RouteProtocol;
use App\Support\Netconf\ResidualXml;
use SimpleXMLElement;

/**
 * Cihazın routing tablosunu NETCONF ile okur.
 *
 * SNMP bu veriyi vermez; switch_routes tablosunun tek kaynağı bu okuyucudur.
 *
 * Açık bir oturum alır: aynı oturumda başka okuyucular da çalışabilsin diye
 * bağlantıyı kendisi açmaz (bkz. .ai/rules/netconf.md).
 */
class RouteReader
{
    /**
     * Panelin MODELLEDİĞİ çocuk düğümler. Bunların dışındaki her şey ham XML
     * olarak saklanır ve geri yazılırken aynen basılır.
     *
     * @var list<string>
     */
    private const KNOWN_CHILDREN = [
        'name', 'next-hop', 'qualified-next-hop', 'discard', 'reject', 'receive',
        'preference', 'metric', 'tag', 'tag2', 'community', 'next-table',
        'retain', 'no-retain', 'readvertise', 'no-readvertise',
        'resolve', 'no-resolve', 'install', 'no-install', 'active', 'passive',
    ];

    /**
     * @return list<array{destination: string, prefix_length: int, next_hop: ?string, protocol: RouteProtocol, family: IpFamily}>
     *
     * @throws NetconfException
     */
    public function routes(NetconfSession $session): array
    {
        $reply = $session->rpc('<get-route-information/>');
        $routes = [];

        foreach ($reply->xpath('//*[local-name()="route-table"]') ?: [] as $table) {
            $family = $this->family(trim((string) ($table->xpath('./*[local-name()="table-name"]')[0] ?? '')));

            // mpls.0, iso.0, __juniper_private__ gibi tablolar envanterde
            // işe yaramaz; yalnızca IP tabloları alınır.
            if ($family === null) {
                continue;
            }

            foreach ($table->xpath('./*[local-name()="rt"]') ?: [] as $rt) {
                $route = $this->route($rt, $family);

                if ($route !== null) {
                    $routes[] = $route;
                }
            }
        }

        return $routes;
    }

    /**
     * Yapılandırmada duran statik route'lar.
     *
     * Routing tablosundan ayrı okunur: sonraki atlaması erişilemeyen statik
     * route config'de durur ama tabloya kurulmaz. Yalnızca tabloya bakılırsa
     * panelden yazılan route kaybolmuş gibi görünür.
     *
     * Her route için cihazdaki BÜTÜN alanlar çıkarılır ve MODELLENİR:
     * bütün sonraki atlamalar (ECMP), qualified-next-hop'lar kendi
     * preference/metric'leriyle, preference, metric, tag, tag2, community,
     * next-table ve boş seçenekler (retain, resolve, passive...).
     *
     * Panelin tanımadığı çocuk düğümler ise ham XML olarak saklanır
     * (residual_xml) ve geri yazılırken aynen basılır. Böylece hiçbir satır
     * kaybolmaz; panel anladığını yazıp anlamadığını silseydi "salt okunur"
     * demek zorunda kalırdı.
     *
     * @return list<array<string, mixed>>
     *
     * @throws NetconfException
     */
    public function configuredStatic(NetconfSession $session): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .'<configuration><routing-options/><routing-instances/></configuration>'
            .'</get-configuration>'
        );

        $routes = [];

        /*
         * Statik route ÜÇ yerde durabilir:
         *
         *   routing-options static                     varsayılan inet.0
         *   routing-options rib <ad> static            IPv6 buradadır (inet6.0)
         *   routing-instances instance <ad> routing-options static
         *
         * Üçüncüsü filtre tabanlı yönlendirmenin (FBF) kalbidir: örneğin
         * "tablo-a" kendi varsayılan yolunu orada taşır; bu yer okunmazsa
         * panel o yolu göremez.
         */
        foreach ($reply->xpath('//*[local-name()="static"]') ?: [] as $static) {
            $rib = $this->ribOf($static);
            $instance = $this->instanceOf($static);

            foreach ($static->xpath('./*[local-name()="route"]') ?: [] as $node) {
                $route = $this->configuredRoute($node, $rib, $instance);

                if ($route !== null) {
                    $routes[] = $route;
                }
            }
        }

        return $routes;
    }

    /**
     * Route'un içinde bulunduğu RIB adı; varsayılan tabloda null.
     */
    /**
     * Route hangi yönlendirme tablosunun içinde?
     *
     * Tablonun içindeki "static" düğümü, routing-options'ın altında ve o da
     * instance'ın altındadır; iki basamak yukarı bakılır. Ana tablodaki
     * route'lar için null döner.
     */
    private function instanceOf(SimpleXMLElement $static): ?string
    {
        $ad = $static->xpath(
            'ancestor::*[local-name()="instance"]/*[local-name()="name"]'
        ) ?: [];

        $deger = trim((string) ($ad[0] ?? ''));

        return $deger === '' ? null : $deger;
    }

    private function ribOf(SimpleXMLElement $static): ?string
    {
        $rib = $static->xpath('parent::*[local-name()="rib"]/*[local-name()="name"]') ?: [];

        $ad = trim((string) ($rib[0] ?? ''));

        return $ad === '' ? null : $ad;
    }

    /**
     * Tek bir yapılandırma route'u, bütün alanlarıyla.
     *
     * @return array<string, mixed>|null
     */
    private function configuredRoute(SimpleXMLElement $node, ?string $rib, ?string $instance = null): ?array
    {
        $name = trim((string) ($node->xpath('./*[local-name()="name"]')[0] ?? ''));

        if ($name === '') {
            return null;
        }

        $family = str_contains($name, ':') ? IpFamily::Inet6 : IpFamily::Inet;
        [$address, $length] = $this->split($name, $family);

        $hops = $this->nextHops($node);

        return [
            'destination' => $address,
            'prefix_length' => $length,
            'rib' => $rib,
            'routing_instance' => $instance,
            'next_hop' => $this->configuredNextHop($node),
            'next_hops' => $hops,
            'qualified_next_hops' => $this->qualifiedNextHops($node),
            'preference' => $this->number($node, 'preference'),
            'metric' => $this->number($node, 'metric'),
            'tag' => $this->number($node, 'tag'),
            'tag2' => $this->number($node, 'tag2'),
            'flags' => $this->flags($node),
            'communities' => $this->texts($node, 'community'),
            'next_table' => $this->text($node, 'next-table'),

            /*
             * Panelin tanımadığı her çocuk ham hâliyle saklanır ve geri
             * yazılırken aynen basılır. "Anlamadığını sessizce sil"
             * hatasının kökten çözümü budur.
             */
            'residual_xml' => ResidualXml::collect($node, self::KNOWN_CHILDREN),
            'unmanageable_reason' => null,
            'family' => $family,
        ];
    }

    /**
     * Kendi preference/metric'i olan sonraki atlamalar.
     *
     * Yedekli çıkışın standart biçimi budur: birincil next-hop, yedeği daha
     * yüksek preference'lı bir qualified-next-hop. Okunmasaydı panel yedeği
     * ne gösterebilir ne de koruyabilirdi.
     *
     * @return list<array{name: string, preference: ?int, metric: ?int, interface: ?string}>
     */
    private function qualifiedNextHops(SimpleXMLElement $node): array
    {
        $hops = [];

        foreach ($node->xpath('./*[local-name()="qualified-next-hop"]') ?: [] as $hop) {
            $name = $this->text($hop, 'name');

            if ($name === null) {
                continue;
            }

            $hops[] = [
                'name' => $name,
                'preference' => $this->number($hop, 'preference'),
                'metric' => $this->number($hop, 'metric'),
                'interface' => $this->text($hop, 'interface'),
            ];
        }

        return $hops;
    }

    /**
     * Boş seçenekler: <retain/>, <no-readvertise/>, <passive/>...
     *
     * @return list<string>
     */
    private function flags(SimpleXMLElement $node): array
    {
        $flags = [];

        foreach (RouteFlag::cases() as $flag) {
            if (($node->xpath('./*[local-name()="'.$flag->value.'"]') ?: []) !== []) {
                $flags[] = $flag->value;
            }
        }

        return $flags;
    }

    /**
     * @return list<string>
     */
    private function texts(SimpleXMLElement $node, string $element): array
    {
        $values = [];

        foreach ($node->xpath('./*[local-name()="'.$element.'"]') ?: [] as $child) {
            $value = trim((string) $child);

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function text(SimpleXMLElement $node, string $element): ?string
    {
        $value = trim((string) ($node->xpath('./*[local-name()="'.$element.'"]')[0] ?? ''));

        return $value === '' ? null : $value;
    }

    private function number(SimpleXMLElement $node, string $element): ?int
    {
        $value = $this->text($node, $element);

        return $value === null || ! ctype_digit($value) ? null : (int) $value;
    }

    /**
     * Yapılandırmadaki BÜTÜN sonraki atlamalar.
     *
     * Junos bir route'a birden çok atlama verebilir (ECMP, yedekli çıkış).
     * Yalnızca ilki okunursa ekran yedeği hiç göstermez ve düzenleme cihazda
     * tek atlamalı bir route bırakır — birinci uplink düştüğünde dış erişim
     * tümden gider.
     *
     * @return list<string>
     */
    private function nextHops(SimpleXMLElement $node): array
    {
        $hops = [];

        foreach ($node->xpath('./*[local-name()="next-hop"]') ?: [] as $hop) {
            $value = trim((string) $hop);

            if ($value !== '') {
                $hops[] = $value;
            }
        }

        return $hops;
    }

    /**
     * Sonraki atlama; yoksa yolun ne yaptığı (discard, reject) saklanır.
     */
    private function configuredNextHop(SimpleXMLElement $node): ?string
    {
        $hop = trim((string) ($node->xpath('./*[local-name()="next-hop"]')[0] ?? ''));

        if ($hop !== '') {
            return $hop;
        }

        foreach (['discard' => 'Discard', 'reject' => 'Reject', 'receive' => 'Receive'] as $element => $label) {
            if (($node->xpath("./*[local-name()=\"{$element}\"]") ?: []) !== []) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @return array{destination: string, prefix_length: int, next_hop: ?string, protocol: RouteProtocol, family: IpFamily}|null
     */
    private function route(SimpleXMLElement $rt, IpFamily $family): ?array
    {
        $destination = trim((string) ($rt->xpath('./*[local-name()="rt-destination"]')[0] ?? ''));

        if ($destination === '') {
            return null;
        }

        // Yalnızca etkin girdi kaydedilir; yedek yollar envanteri şişirir.
        $entry = $rt->xpath('./*[local-name()="rt-entry"]')[0] ?? null;

        if ($entry === null) {
            return null;
        }

        [$address, $length] = $this->split($destination, $family);

        return [
            'destination' => $address,
            'prefix_length' => $length,
            'next_hop' => $this->nextHop($entry),
            'protocol' => $this->protocol(trim((string) ($entry->xpath('./*[local-name()="protocol-name"]')[0] ?? ''))),
            'family' => $family,
        ];
    }

    /**
     * Sonraki adres: önce gerçek bir sonraki atlama (to), yoksa çıkış arayüzü
     * (via). Reject ve Discard yollarında ikisi de yoktur.
     */
    private function nextHop(SimpleXMLElement $entry): ?string
    {
        foreach (['to', 'via', 'nh-local-interface'] as $element) {
            $value = trim((string) ($entry->xpath(".//*[local-name()=\"{$element}\"]")[0] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        $type = trim((string) ($entry->xpath('./*[local-name()="nh-type"]')[0] ?? ''));

        return $type === '' ? null : $type;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function split(string $destination, IpFamily $family): array
    {
        if (! str_contains($destination, '/')) {
            return [$destination, $family === IpFamily::Inet6 ? 128 : 32];
        }

        [$address, $length] = explode('/', $destination, 2);

        return [$address, (int) $length];
    }

    /**
     * Tablo adı inet.0, inet6.0, mpls.0 gibidir. IP olmayan tablolar için
     * null döner ve tablo atlanır.
     */
    private function family(string $tableName): ?IpFamily
    {
        return match (true) {
            str_starts_with($tableName, 'inet6') => IpFamily::Inet6,
            str_starts_with($tableName, 'inet') => IpFamily::Inet,
            default => null,
        };
    }

    private function protocol(string $name): RouteProtocol
    {
        return match (strtolower($name)) {
            'static' => RouteProtocol::Static,
            'direct' => RouteProtocol::Direct,
            'local' => RouteProtocol::Local,
            'ospf', 'ospf3' => RouteProtocol::Ospf,
            'bgp' => RouteProtocol::Bgp,
            default => RouteProtocol::Other,
        };
    }
}
