<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\FirewallAction;
use App\Enums\FirewallFamily;
use App\Support\Netconf\ResidualXml;
use App\Support\Netconf\XmlNode;
use SimpleXMLElement;

/**
 * Cihazdaki firewall filtrelerini, policer'ları ve bağlamaları okur.
 *
 * NEDEN İKİ KATMAN: güncelleme filtreyi silip bütün terimleriyle yeniden
 * yazar (Junos'ta terim SIRASI anlamlıdır ve merge yeni terimi hep sona
 * ekler). Panel yalnızca modellediği alanları geri üretseydi, modellemediği
 * her satır o sırada sessizce kaybolurdu; filtreyi "salt okunur" ilan etmek
 * ise kullanıcının onu hiç yönetememesi demektir.
 *
 * Bu yüzden iki katman birden var ve ikisi de gerekli:
 *
 *   1. Modellenen alan kümesi Junos'un pratik yüzeyine kadar geniş: üç aile
 *      (inet, inet6, ethernet-switching), filtre seviyesinde
 *      interface-specific, terimde tcp-flags'ten MAC adresine kadar koşullar
 *      ve policer'dan sample'a kadar eylemler.
 *   2. Geri kalan uzun kuyruk ham XML olarak saklanır (residual_xml) ve geri
 *      yazılırken aynen basılır.
 *
 * Sonuç: is_manageable HER ZAMAN true. Hiçbir filtre salt okunur değildir
 * çünkü hiçbir satır kaybolmaz.
 *
 * Bağlamalar (hangi arayüz, hangi yön) da okunur: bir filtreyi düzenlemeden
 * önce nerede kullanıldığını bilmek şarttır.
 */
class FirewallReader
{
    /**
     * Panelin tanıdığı "from" koşulları: XML elemanı => model alanı.
     *
     * Değerleri alt elemanlı gelenler (name çocuğu) ile düz metin olanlar
     * ayrı ayrı düşünülmez; values() ikisini de karşılar.
     *
     * @var array<string, string>
     */
    private const MATCHES = [
        'source-address' => 'source_addresses',
        'destination-address' => 'destination_addresses',
        'protocol' => 'protocols',
        'port' => 'ports',
        'source-port' => 'source_ports',
        'destination-port' => 'destination_ports',
        'icmp-type' => 'icmp_types',
        'icmp-code' => 'icmp_codes',
        'dscp' => 'dscps',
        'precedence' => 'precedences',
        'packet-length' => 'packet_lengths',
        'ttl' => 'ttls',
        'ip-options' => 'ip_options',
        'fragment-flags' => 'fragment_flags',
        'interface' => 'interfaces',
        'next-header' => 'next_headers',
        'traffic-class' => 'traffic_classes',
        'ether-type' => 'ether_types',
        'source-mac-address' => 'source_mac_addresses',
        'destination-mac-address' => 'destination_mac_addresses',
    ];

    /**
     * "except" işareti taşıyabilen koşullar: XML elemanı => [ad alanı, except alanı].
     *
     * Junos "source-prefix-list { A; B except; }" yazabiliyor ve "except"
     * koşulu TERSİNE çevirir. Okunmazsa panel B'yi normal bir eşleşme sanıp
     * geri yazar; filtre sessizce ters çalışır.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PREFIX_LISTS = [
        'source-prefix-list' => ['source_prefix_lists', 'source_prefix_list_excepts'],
        'destination-prefix-list' => ['destination_prefix_lists', 'destination_prefix_list_excepts'],
    ];

    /**
     * "from" altındaki boş işaretler: XML elemanı => model alanı.
     *
     * @var array<string, string>
     */
    private const MATCH_FLAGS = [
        'tcp-initial' => 'is_tcp_initial',
        'tcp-established' => 'is_tcp_established',
        'is-fragment' => 'is_fragment',
        'first-fragment' => 'is_first_fragment',
    ];

    /**
     * "then" altındaki metin taşıyan eylemler: XML elemanı => model alanı.
     *
     * Sayaç adı DÜZ METİNDİR (<count>sayac</count>); alt elemanlı bir biçim
     * beklemek "syntax error" verir (cihazda görüldü).
     *
     * @var array<string, string>
     */
    private const ACTION_VALUES = [
        'count' => 'count_name',
        'routing-instance' => 'routing_instance',
        'policer' => 'policer',
        'forwarding-class' => 'forwarding_class',
        'loss-priority' => 'loss_priority',
        'dscp' => 'dscp',
        'analyzer' => 'analyzer',
    ];

    /**
     * "then" altındaki boş işaretler: XML elemanı => model alanı.
     *
     * @var array<string, string>
     */
    private const ACTION_FLAGS = [
        'log' => 'is_logged',
        'syslog' => 'is_syslogged',
        'port-mirror' => 'is_port_mirrored',
        'sample' => 'is_sampled',
    ];

    /**
     * Filtrenin KENDİ altında panelin ürettiği elemanlar.
     *
     * "interface-specific" arayüz başına sayaç için gerekir ve filtrenin
     * altındadır; panel onu okumasaydı her güncellemede silinirdi.
     *
     * @var list<string>
     */
    private const FILTER_CHILDREN = ['name', 'interface-specific', 'term'];

    /**
     * Terimin KENDİ altında panelin ürettiği elemanlar.
     *
     * @var list<string>
     */
    private const TERM_CHILDREN = ['name', 'from', 'then'];

    /**
     * Policer'ın panelin ürettiği elemanları.
     *
     * @var list<string>
     */
    private const POLICER_CHILDREN = ['name', 'filter-specific', 'if-exceeding', 'then'];

    /**
     * Tek RPC, iki cevap: filtreler ve policer'lar aynı yanıttadır.
     *
     * Oturum pahalı, RPC ucuzdur; ama aynı yapılandırmayı iki kez istemenin
     * de anlamı yok (bkz. .ai/rules/netconf.md).
     *
     * @return array{filters: list<array<string, mixed>>, policers: list<array<string, mixed>>}
     *
     * @throws NetconfException
     */
    public function read(NetconfSession $session): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .'<configuration><firewall/><interfaces/></configuration>'
            .'</get-configuration>'
        );

        return [
            'filters' => $this->parse($reply),
            'policers' => $this->parsePolicers($reply),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws NetconfException
     */
    public function filters(NetconfSession $session): array
    {
        return $this->read($session)['filters'];
    }

    /**
     * Ayrıştırma ayrı durur: aynı yanıttan başka bir soru soran okuyucular
     * (çıkış bekçisi gibi) RPC'yi tekrarlamadan bunu kullanabilsin.
     *
     * @return list<array<string, mixed>>
     */
    public function parse(SimpleXMLElement $reply): array
    {
        $bindings = $this->bindings($reply);
        $filters = [];

        foreach ($reply->xpath('//*[local-name()="firewall"]//*[local-name()="filter"]') ?: [] as $node) {
            $name = $this->text($node, 'name');

            if ($name === null) {
                continue;
            }

            $terms = [];
            $position = 0;

            foreach ($node->xpath('./*[local-name()="term"]') ?: [] as $termNode) {
                $term = $this->term($termNode, $position);

                if ($term !== null) {
                    $terms[] = $term;
                    $position++;
                }
            }

            $filters[] = [
                'name' => $name,
                'family' => $this->family($node),
                'is_inactive' => XmlNode::isInactive($node),
                'interface_specific' => $this->has($node, 'interface-specific'),

                /*
                 * Panel hiçbir filtreyi salt okunur saymaz: tanımadığı
                 * satırlar ham hâlleriyle korunur ve geri yazılır.
                 */
                'is_manageable' => true,
                'residual_xml' => ResidualXml::collect($node, self::FILTER_CHILDREN),
                'bindings' => $bindings[$name] ?? [],
                'terms' => $terms,
            ];
        }

        return $filters;
    }

    /**
     * "firewall policer <ad>" düğümleri.
     *
     * Filtrenin DIŞINDADIR: terim onu "then policer <ad>" ile çağırır. Panel
     * onu okumasaydı policer kullanan her filtre salt okunur kalırdı.
     *
     * @return list<array<string, mixed>>
     */
    public function parsePolicers(SimpleXMLElement $reply): array
    {
        $policers = [];

        foreach ($reply->xpath('//*[local-name()="firewall"]/*[local-name()="policer"]') ?: [] as $node) {
            $name = $this->text($node, 'name');

            if ($name === null) {
                continue;
            }

            $limit = $node->xpath('./*[local-name()="if-exceeding"]')[0] ?? null;
            $then = $node->xpath('./*[local-name()="then"]')[0] ?? null;

            $policers[] = [
                'name' => $name,
                'filter_specific' => $this->has($node, 'filter-specific'),
                'bandwidth_limit' => $limit === null ? null : $this->text($limit, 'bandwidth-limit'),
                'bandwidth_percent' => $limit === null ? null : $this->number($limit, 'bandwidth-percent'),
                'burst_size_limit' => $limit === null ? null : $this->text($limit, 'burst-size-limit'),
                'is_discard' => $then !== null && $this->has($then, 'discard'),
                'forwarding_class' => $then === null ? null : $this->text($then, 'forwarding-class'),
                'loss_priority' => $then === null ? null : $this->text($then, 'loss-priority'),
                'residual_xml' => ResidualXml::collect($node, self::POLICER_CHILDREN),
            ];
        }

        return $policers;
    }

    /**
     * Filtre "family inet", "family inet6" ya da "family ethernet-switching"
     * altında durur. Aile bildirilmemişse Junos inet varsayar.
     */
    private function family(SimpleXMLElement $filter): FirewallFamily
    {
        foreach ($filter->xpath('ancestor::*') ?: [] as $ancestor) {
            $aile = FirewallFamily::tryFrom($ancestor->getName());

            if ($aile !== null) {
                return $aile;
            }
        }

        return FirewallFamily::Inet;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function term(SimpleXMLElement $node, int $position): ?array
    {
        $name = $this->text($node, 'name');

        if ($name === null) {
            return null;
        }

        $from = $node->xpath('./*[local-name()="from"]')[0] ?? null;
        $then = $node->xpath('./*[local-name()="then"]')[0] ?? null;

        return [
            'name' => $name,
            'position' => $position,
            'is_inactive' => XmlNode::isInactive($node),
            ...$this->conditions($from),
            ...$this->actions($then),

            /*
             * Korunan ham satırlar ÜÇ yerde birden toplanır: terimin kendi
             * altında, "from"un altında ve "then"in altında. Gövde yazarken
             * üçü de modellenen alanlardan yeniden kuruluyor; yalnızca terim
             * seviyesi saklansaydı from içindeki tanınmayan koşul yine
             * kaybolurdu.
             */
            'residual_xml' => ResidualXml::collect($node, self::TERM_CHILDREN),
            'from_residual_xml' => $from === null ? [] : ResidualXml::collect($from, $this->knownFromChildren()),
            'then_residual_xml' => $then === null ? [] : ResidualXml::collect($then, $this->knownThenChildren()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conditions(?SimpleXMLElement $from): array
    {
        $term = [];

        foreach (self::MATCHES as $element => $field) {
            $term[$field] = $from === null ? [] : $this->values($from, $element);
        }

        foreach (self::PREFIX_LISTS as $element => [$field, $exceptField]) {
            [$term[$field], $term[$exceptField]] = $from === null
                ? [[], []]
                : $this->prefixLists($from, $element);
        }

        foreach (self::MATCH_FLAGS as $element => $field) {
            $term[$field] = $from !== null && $this->has($from, $element);
        }

        $term['tcp_flags'] = $from === null ? null : $this->text($from, 'tcp-flags');

        return $term;
    }

    /**
     * @return array<string, mixed>
     */
    private function actions(?SimpleXMLElement $then): array
    {
        $term = ['action' => $then === null ? null : $this->action($then)];

        foreach (self::ACTION_VALUES as $element => $field) {
            $term[$field] = $then === null ? null : $this->actionValue($then, $element);
        }

        foreach (self::ACTION_FLAGS as $element => $field) {
            $term[$field] = $then !== null && $this->has($then, $element);
        }

        // "then next term": zinciri bitirmez, sıradaki terime geçer.
        $term['goes_to_next_term'] = $then !== null && $this->text($then, 'next') === 'term';

        return $term;
    }

    /**
     * "then" altındaki değer — DÜZ ya da SARMALANMIŞ olabilir.
     *
     * Junos hepsini aynı biçimde yazmıyor ve fark ölçülerek bulundu:
     *
     *     <count>sayac</count>                                       düz
     *     <policer>udp-filtre-limit</policer>                        düz
     *     <routing-instance>
     *       <routing-instance-name>tablo-a</routing-instance-name>
     *     </routing-instance>                                        sarmalanmış
     *
     * Düz metin arayan okuyucu sarmalanmış olanı NULL görür. Sonucu sessiz
     * ve ağırdır: filtre güncellemesi filtreyi SİLİP yeniden yazıyor,
     * eleman "bilinen" sayıldığı için ham XML'de de korunmuyor — trafiği
     * tablo-a'ya yönlendiren bir "then routing-instance" satırı, terime
     * dokunan ilk düzenlemede tamamen kaybolurdu.
     *
     * Çocuğu olan elemanda çocuğun metni alınır; düz elemanda davranış
     * değişmez.
     */
    private function actionValue(SimpleXMLElement $then, string $element): ?string
    {
        $node = $then->xpath('./*[local-name()="'.$element.'"]')[0] ?? null;

        if ($node === null) {
            return null;
        }

        foreach ($node->children() as $child) {
            $value = trim((string) $child);

            return $value === '' ? null : $value;
        }

        $value = trim((string) $node);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function knownFromChildren(): array
    {
        return [
            ...array_keys(self::MATCHES),
            ...array_keys(self::PREFIX_LISTS),
            ...array_keys(self::MATCH_FLAGS),
            'tcp-flags',
        ];
    }

    /**
     * @return list<string>
     */
    private function knownThenChildren(): array
    {
        return [
            ...array_map(static fn (FirewallAction $action): string => $action->value, FirewallAction::cases()),
            ...array_keys(self::ACTION_VALUES),
            ...array_keys(self::ACTION_FLAGS),
            'next',
        ];
    }

    private function action(SimpleXMLElement $then): ?FirewallAction
    {
        foreach (FirewallAction::cases() as $action) {
            if ($this->has($then, $action->value)) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Prefix list koşulu: adlar ve hangilerinin "except" taşıdığı.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function prefixLists(SimpleXMLElement $from, string $element): array
    {
        $names = [];
        $excepts = [];

        foreach ($from->xpath('./*[local-name()="'.$element.'"]') ?: [] as $node) {
            $name = $this->text($node, 'name') ?? trim((string) $node);

            if ($name === '') {
                continue;
            }

            $names[] = $name;

            if ($this->has($node, 'except')) {
                $excepts[] = $name;
            }
        }

        return [$names, $excepts];
    }

    /**
     * Koşul değerleri. Adres ve MAC listeleri alt elemanlı gelir
     * (<source-address><name>x</name></source-address>), protokol ve port ise
     * doğrudan metindir.
     *
     * @return list<string>
     */
    private function values(SimpleXMLElement $from, string $element): array
    {
        $values = [];

        foreach ($from->xpath('./*[local-name()="'.$element.'"]') ?: [] as $node) {
            $inner = $this->text($node, 'name');
            $value = $inner ?? trim((string) $node);

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Filtre adı => bağlı olduğu arayüzler ve yönleri.
     *
     * @return array<string, list<array{interface: string, direction: string}>>
     */
    private function bindings(SimpleXMLElement $reply): array
    {
        $bindings = [];

        foreach ($reply->xpath('//*[local-name()="interfaces"]/*[local-name()="interface"]') ?: [] as $interface) {
            $name = $this->text($interface, 'name');

            if ($name === null) {
                continue;
            }

            foreach ($interface->xpath('./*[local-name()="unit"]') ?: [] as $unit) {
                $number = $this->text($unit, 'name') ?? '0';

                foreach (['input', 'output'] as $direction) {
                    foreach ($unit->xpath('.//*[local-name()="filter"]/*[local-name()="'.$direction.'"]') ?: [] as $node) {
                        $filter = $this->text($node, 'filter-name') ?? trim((string) $node);

                        if ($filter !== '') {
                            $bindings[$filter][] = [
                                'interface' => "{$name}.{$number}",
                                'direction' => $direction,
                            ];
                        }
                    }
                }
            }
        }

        return $bindings;
    }

    private function text(SimpleXMLElement $node, string $path): ?string
    {
        $parts = array_map(
            static fn (string $part): string => '*[local-name()="'.$part.'"]',
            explode('/', $path),
        );

        $found = $node->xpath('./'.implode('/', $parts))[0] ?? null;

        if ($found === null) {
            return null;
        }

        $value = trim((string) $found);

        return $value === '' ? null : $value;
    }

    private function number(SimpleXMLElement $node, string $element): ?int
    {
        $value = $this->text($node, $element);

        return $value === null || ! ctype_digit($value) ? null : (int) $value;
    }

    private function has(SimpleXMLElement $node, string $name): bool
    {
        $found = $node->xpath('./*[local-name()="'.$name.'"]');

        return $found !== false && $found !== [];
    }
}
