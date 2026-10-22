<?php

declare(strict_types=1);

namespace App\Support\Netconf;

use App\Enums\PrefixMatchType;
use App\Enums\RouteFilterMatchType;
use SimpleXMLElement;

/**
 * Bir politikanın ya da teriminin "from" koşulları.
 *
 * Aynı yapı İKİ yerde geçer ve ikisi de yönetilebilir olmalı:
 *
 *   policy-statement X { term t { from { ... } } from { ... } }
 *
 * İkinci "from" politikanın KENDİ koşuludur (terimsiz politika biçimi);
 * cihazda geçerli olduğu ölçüldü (test1, commit check):
 * `policy-statement { from { protocol direct; } then { accept; } }`.
 *
 * Okuma kuralı: bir çocuk düğüm ancak GERÇEKTEN çözümlendiyse "tanınmış"
 * sayılır. Çözümlenemeyen düğüm artığa (residual) düşer ve geri yazılırken
 * ham hâliyle basılır — panelin anlamadığı hiçbir satır kaybolmaz.
 */
final class PolicyMatch
{
    /**
     * Metin listesi olarak okunan koşullar: dizi anahtarı => Junos elemanı.
     *
     * @var array<string, string>
     */
    private const TEXT_LISTS = [
        'protocols' => 'protocol',
        'as_paths' => 'as-path',
        'communities' => 'community',
        'neighbors' => 'neighbor',
        'next_hops' => 'next-hop',
        'interfaces' => 'interface',
        'route_types' => 'route-type',
        'families' => 'family',
        'areas' => 'area',
        'levels' => 'level',
        'tags' => 'tag',
        'ribs' => 'rib',
    ];

    /**
     * Boş bir koşul kümesi. Formlar ve varsayılanlar buradan başlar.
     *
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return array_merge(
            ['prefix_lists' => [], 'prefix_list_filters' => [], 'route_filters' => [], 'residual' => []],
            array_fill_keys(array_keys(self::TEXT_LISTS), []),
        );
    }

    /**
     * Cihazdan gelen <from> düğümünü çözümler.
     *
     * @return array<string, mixed>
     */
    public static function read(?SimpleXMLElement $from): array
    {
        $match = self::blank();

        if ($from === null) {
            return $match;
        }

        $known = [];

        foreach (self::TEXT_LISTS as $key => $element) {
            $values = XmlNode::texts($from, $element);

            if ($values !== []) {
                $match[$key] = $values;
                $known[] = $element;
            }
        }

        $match['prefix_lists'] = XmlNode::childTexts($from, 'prefix-list', 'name');

        if ($match['prefix_lists'] !== []) {
            $known[] = 'prefix-list';
        }

        $match['prefix_list_filters'] = self::prefixListFilters($from, $known);
        $match['route_filters'] = self::routeFilters($from, $known);

        // Çözümlenmemiş her çocuk ham hâliyle korunur.
        $match['residual'] = ResidualXml::collect($from, $known);

        return $match;
    }

    /**
     * Koşulları cihaza yazılacak <from> gövdesine çevirir.
     *
     * Koşulsuz terim HER ŞEYE uyar; hiçbir koşul yoksa düğüm hiç yazılmaz.
     *
     * @param  array<string, mixed>|null  $match
     */
    public static function emit(?array $match): string
    {
        if ($match === null) {
            return '';
        }

        $body = '';

        foreach ($match['prefix_lists'] ?? [] as $list) {
            $body .= XmlNode::wrap('prefix-list', '<name>'.XmlNode::escape((string) $list).'</name>');
        }

        $body .= self::emitPrefixListFilters($match['prefix_list_filters'] ?? []);
        $body .= self::emitRouteFilters($match['route_filters'] ?? []);

        foreach (self::TEXT_LISTS as $key => $element) {
            $body .= XmlNode::leaves($element, $match[$key] ?? []);
        }

        $body .= ResidualXml::emit($match['residual'] ?? null);

        return $body === '' ? '' : "<from>{$body}</from>";
    }

    /**
     * Ekranda tek satırlık özet.
     *
     * @param  array<string, mixed>|null  $match
     * @return list<string>
     */
    public static function summary(?array $match): array
    {
        if ($match === null) {
            return [];
        }

        $fragments = [];

        foreach ($match['prefix_lists'] ?? [] as $list) {
            $fragments[] = "prefix-list {$list}";
        }

        foreach ($match['prefix_list_filters'] ?? [] as $filter) {
            $fragments[] = 'prefix-list-filter '.($filter['list'] ?? '').' '.($filter['match_type'] ?? '');
        }

        foreach ($match['route_filters'] ?? [] as $filter) {
            $fragments[] = trim('route-filter '.($filter['address'] ?? '').' '
                .($filter['match_type'] ?? '').' '.($filter['value'] ?? ''));
        }

        foreach (self::TEXT_LISTS as $key => $element) {
            foreach ($match[$key] ?? [] as $value) {
                $fragments[] = "{$element} {$value}";
            }
        }

        foreach (ResidualXml::names($match['residual'] ?? null) as $name) {
            $fragments[] = $name;
        }

        return $fragments;
    }

    /**
     * @param  array<string, mixed>|null  $match
     */
    public static function isEmpty(?array $match): bool
    {
        return self::emit($match) === '';
    }

    /**
     * Junos bu alanı ALT ÇİZGİYLE yazar: <list_name>. Şemanın geri kalanı
     * tire kullandığı için kolayca gözden kaçar.
     *
     * @param  list<string>  $taninan
     * @return list<array{list: string, match_type: string}>
     */
    private static function prefixListFilters(SimpleXMLElement $from, array &$known): array
    {
        $filters = [];

        foreach (XmlNode::children($from, 'prefix-list-filter') as $filter) {
            $list = XmlNode::text($filter, 'list_name');
            $type = XmlNode::firstFlag($filter, array_column(PrefixMatchType::cases(), 'value'));

            // Tür Junos'ta ZORUNLUDUR; ikisi de yoksa çözümleyemedik demektir.
            if ($list === null || $type === null) {
                continue;
            }

            $filters[] = ['list' => $list, 'match_type' => $type];
        }

        if ($filters !== [] && count($filters) === count(XmlNode::children($from, 'prefix-list-filter'))) {
            $known[] = 'prefix-list-filter';
        }

        return $filters;
    }

    /**
     * route-filter: adres + eşleşme türü, tür değer alıyorsa değeriyle.
     *
     * @param  list<string>  $taninan
     * @return list<array{address: string, match_type: string, value: ?string}>
     */
    private static function routeFilters(SimpleXMLElement $from, array &$known): array
    {
        $filters = [];
        $nodes = XmlNode::children($from, 'route-filter');

        foreach ($nodes as $node) {
            $address = XmlNode::text($node, 'address');
            $type = null;
            $value = null;

            foreach (RouteFilterMatchType::cases() as $case) {
                $found = XmlNode::children($node, $case->value);

                if ($found === []) {
                    continue;
                }

                $type = $case;
                $value = trim((string) $found[0]) ?: null;
            }

            if ($address === null || $type === null) {
                continue;
            }

            $filters[] = ['address' => $address, 'match_type' => $type->value, 'value' => $value];
        }

        if ($filters !== [] && count($filters) === count($nodes)) {
            $known[] = 'route-filter';
        }

        return $filters;
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     */
    private static function emitPrefixListFilters(array $filters): string
    {
        $body = '';

        foreach ($filters as $filter) {
            $list = trim((string) ($filter['list'] ?? ''));

            if ($list === '') {
                continue;
            }

            /*
             * Eşleşme türü ZORUNLUDUR: türsüz filtre "syntax error, expecting
             * <flag-value> or <attribute-value>" ile reddedilir. Tür
             * verilmemişse en yaygın kullanım olan orlonger yazılır.
             */
            $type = PrefixMatchType::tryFrom((string) ($filter['match_type'] ?? '')) ?? PrefixMatchType::OrLonger;

            $body .= XmlNode::wrap(
                'prefix-list-filter',
                '<list_name>'.XmlNode::escape($list).'</list_name><'.$type->value.'/>',
            );
        }

        return $body;
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     */
    private static function emitRouteFilters(array $filters): string
    {
        $body = '';

        foreach ($filters as $filter) {
            $address = trim((string) ($filter['address'] ?? ''));
            $type = RouteFilterMatchType::tryFrom((string) ($filter['match_type'] ?? ''));

            if ($address === '' || $type === null) {
                continue;
            }

            $inner = '<address>'.XmlNode::escape($address).'</address>';
            $value = trim((string) ($filter['value'] ?? ''));

            $inner .= $type->needsValue() && $value !== ''
                ? '<'.$type->value.'>'.XmlNode::escape($value).'</'.$type->value.'>'
                : '<'.$type->value.'/>';

            $body .= XmlNode::wrap('route-filter', $inner);
        }

        return $body;
    }
}
