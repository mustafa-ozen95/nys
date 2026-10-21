<?php

declare(strict_types=1);

namespace App\View\Components\Policy;

use App\Support\Netconf\PolicyMatch;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Politika koşulları ("from") formu.
 *
 * Aynı bileşen İKİ yerde kullanılır: terimin koşulları ve politikanın KENDİ
 * koşulu (terimsiz politika biçimi). İki ekran için iki düzen yazılmaz
 * (bkz. .ai/rules/interface.md).
 *
 * Sınıf bileşenidir çünkü hazırlanacak veri var: Junos "leaf-list" alanları
 * formda tek satırda virgülle yazılır, kayıtta ise dizidir. Bu dönüşüm
 * Blade'in içinde yapılamaz.
 */
class MatchFields extends Component
{
    /**
     * Virgülle yazılan koşullar. Junos bunları leaf-list olarak tutar
     * (`from protocol [ bgp ospf ]`).
     *
     * @var list<array{key: string, label: string, hint: string}>
     */
    private const TEXTS = [
        ['key' => 'protocols', 'label' => 'Protokol', 'hint' => 'Yolun öğrenildiği protokol: bgp, ospf, static, direct, local, aggregate.'],
        ['key' => 'neighbors', 'label' => 'Komşu adresi', 'hint' => 'Yolu duyuran komşunun adresi.'],
        ['key' => 'next_hops', 'label' => 'Sonraki atlama', 'hint' => 'Yolun sonraki atlama adresi.'],
        ['key' => 'interfaces', 'label' => 'Arayüz', 'hint' => 'ge-0/0/1.0, vlan.100 gibi.'],
        ['key' => 'route_types', 'label' => 'Yol türü', 'hint' => 'internal ya da external.'],
        ['key' => 'families', 'label' => 'Adres ailesi', 'hint' => 'inet ya da inet6. Seçilirse route-filter adresleri de aynı aileden olmalı.'],
        ['key' => 'ribs', 'label' => 'RIB', 'hint' => 'inet.0, inet6.0 gibi tablo adı.'],
        ['key' => 'tags', 'label' => 'Etiket (tag)', 'hint' => 'Statik route’a verilen sayısal etiket.'],
        ['key' => 'levels', 'label' => 'Seviye (level)', 'hint' => 'IS-IS seviyesi: 1 ya da 2.'],
        ['key' => 'areas', 'label' => 'Alan (area)', 'hint' => 'OSPF alanı: 0.0.0.0 gibi.'],
    ];

    /**
     * @var list<array{key: string, label: string, hint: string, name: string, id: string, value: string}>
     */
    public array $textRows;

    /**
     * Tekrarlı route-filter satırları. En az bir satır çizilir: boş listede
     * "ekle" düğmesinin kopyalayacağı satır olmazdı.
     *
     * @var list<array{index: int, address: string, match_type: string, value: string}>
     */
    public array $routeFilterRows;

    /** @var list<string> */
    public array $selectedPrefixLists;

    /** @var list<string> */
    public array $selectedAsPaths;

    /** @var list<string> */
    public array $selectedCommunities;

    /**
     * @param  array<string, mixed>|null  $match
     * @param  array<string, string>  $prefixListOptions
     * @param  array<string, string>  $asPathChoices
     * @param  array<string, string>  $communityChoices
     * @param  array<string, string>  $routeFilterTypeOptions
     */
    public function __construct(
        public string $field = 'match',
        public string $prefix = 'm',
        ?array $match = null,
        public array $prefixListOptions = [],
        public array $asPathChoices = [],
        public array $communityChoices = [],
        public array $routeFilterTypeOptions = [],
    ) {
        $match ??= PolicyMatch::blank();

        $this->textRows = $this->rows($match);
        $this->routeFilterRows = $this->filterRows($match);
        $this->selectedPrefixLists = array_map('strval', (array) ($match['prefix_lists'] ?? []));
        $this->selectedAsPaths = array_map('strval', (array) ($match['as_paths'] ?? []));
        $this->selectedCommunities = array_map('strval', (array) ($match['communities'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $match
     * @return list<array{key: string, label: string, hint: string, name: string, id: string, value: string}>
     */
    private function rows(array $match): array
    {
        return array_map(fn (array $row): array => [
            ...$row,
            'name' => "{$this->field}[{$row['key']}]",
            'id' => "{$this->prefix}-{$row['key']}",
            'value' => implode(', ', (array) ($match[$row['key']] ?? [])),
        ], self::TEXTS);
    }

    /**
     * @param  array<string, mixed>  $match
     * @return list<array{index: int, address: string, match_type: string, value: string}>
     */
    private function filterRows(array $match): array
    {
        $rows = [];

        foreach ((array) ($match['route_filters'] ?? []) as $filter) {
            $rows[] = [
                'index' => count($rows),
                'address' => (string) ($filter['address'] ?? ''),
                'match_type' => (string) ($filter['match_type'] ?? ''),
                'value' => (string) ($filter['value'] ?? ''),
            ];
        }

        return $rows === []
            ? [['index' => 0, 'address' => '', 'match_type' => '', 'value' => '']]
            : $rows;
    }

    public function render(): View
    {
        return view('components.policy.match-fields');
    }
}
