<?php

declare(strict_types=1);

namespace App\View\Components\Policy;

use App\Support\Netconf\PolicyThen;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Politika eylemleri ("then") formu.
 *
 * Terimin eylemleri ve politikanın VARSAYILAN EYLEMİ aynı bileşendir.
 * Sonlandırıcı eylem (accept/reject/next) ayrı bir alandır: terimde
 * "action", politikanın varsayılanında "default_then[action]".
 *
 * Sınıf bileşenidir: işleçli sayısal eylemler (local-preference add 10) ve
 * community satırları formda hazır dizi olmalı, Blade hesap yapmaz.
 */
class ThenFields extends Component
{
    /**
     * İşleçli sayısal eylemler: Junos add/subtract kabul eder.
     *
     * @var list<array{key: string, label: string, hint: string}>
     */
    private const VALUES = [
        ['key' => 'local_preference', 'label' => 'Local preference', 'hint' => 'iBGP’de yolu seçtiren değer; büyük olan kazanır.'],
        ['key' => 'metric', 'label' => 'Metrik (MED)', 'hint' => 'Komşuya duyurulan metrik; küçük olan tercih edilir.'],
        ['key' => 'metric2', 'label' => 'İkinci metrik', 'hint' => 'metric2; yalnızca gerekli olduğunda kullanılır.'],
        ['key' => 'preference', 'label' => 'Tercih (preference)', 'hint' => 'Yolun yerel tercihi; küçük olan kazanır.'],
    ];

    /**
     * @var list<array{key: string, label: string, hint: string}>
     */
    private const TEXTS = [
        ['key' => 'as_path_prepend', 'label' => 'AS yolu ekle (as-path-prepend)', 'hint' => 'Boşlukla ayrılmış AS numaraları: “65000 65000”. Yolu uzatarak trafiği başka çıkışa iter.'],
        ['key' => 'as_path_expand', 'label' => 'AS yolu genişlet (as-path-expand)', 'hint' => 'Boşlukla ayrılmış AS numaraları.'],
        ['key' => 'next_hop', 'label' => 'Sonraki atlama', 'hint' => 'Yolun sonraki atlamasını değiştirir.'],
        ['key' => 'origin', 'label' => 'Köken (origin)', 'hint' => 'igp, egp ya da incomplete.'],
        ['key' => 'tag', 'label' => 'Etiket (tag)', 'hint' => 'Yola sayısal etiket yazar.'],
        ['key' => 'load_balance', 'label' => 'Yük dağıtımı', 'hint' => 'per-packet: eşit maliyetli yollara dağıt.'],
        ['key' => 'install_nexthop', 'label' => 'install-nexthop', 'hint' => 'Kurulacak sonraki atlamayı seçer.'],
        ['key' => 'forwarding_class', 'label' => 'Yönlendirme sınıfı', 'hint' => 'forwarding-class adı.'],
        ['key' => 'damping', 'label' => 'Damping profili', 'hint' => 'Tanımlı damping profilinin adı.'],
    ];

    /**
     * @var list<array{key: string, label: string, hint: string, operationName: string, valueName: string, id: string, operation: string, value: string}>
     */
    public array $valueRows;

    /**
     * @var list<array{key: string, label: string, hint: string, name: string, id: string, value: string}>
     */
    public array $textRows;

    /**
     * @var list<array{index: int, operation: string, name: string}>
     */
    public array $communityRows;

    /**
     * @param  array<string, mixed>|null  $then
     * @param  array<string, string>  $actionOptions
     * @param  array<string, string>  $valueOperationOptions
     * @param  array<string, string>  $communityOperationOptions
     * @param  array<string, string>  $communityChoices
     */
    public function __construct(
        public string $field = 'then',
        public string $prefix = 't',
        ?array $then = null,
        public string $actionName = 'action',
        public ?string $actionValue = null,
        public array $actionOptions = [],
        public array $valueOperationOptions = [],
        public array $communityOperationOptions = [],
        public array $communityChoices = [],
    ) {
        $then ??= PolicyThen::blank();

        $this->valueRows = $this->values($then);
        $this->textRows = $this->texts($then);
        $this->communityRows = $this->communities($then);
    }

    /**
     * @param  array<string, mixed>  $then
     * @return list<array<string, string>>
     */
    private function values(array $then): array
    {
        return array_map(function (array $row) use ($then): array {
            $current = (array) ($then[$row['key']] ?? []);

            return [
                ...$row,
                'operationName' => "{$this->field}[{$row['key']}][operation]",
                'valueName' => "{$this->field}[{$row['key']}][value]",
                'id' => "{$this->prefix}-{$row['key']}",
                'operation' => (string) ($current['operation'] ?? 'set'),
                'value' => (string) ($current['value'] ?? ''),
            ];
        }, self::VALUES);
    }

    /**
     * @param  array<string, mixed>  $then
     * @return list<array<string, string>>
     */
    private function texts(array $then): array
    {
        return array_map(fn (array $row): array => [
            ...$row,
            'name' => "{$this->field}[{$row['key']}]",
            'id' => "{$this->prefix}-{$row['key']}",
            'value' => (string) ($then[$row['key']] ?? ''),
        ], self::TEXTS);
    }

    /**
     * En az bir satır çizilir: boş listede "ekle" düğmesinin kopyalayacağı
     * satır olmazdı.
     *
     * @param  array<string, mixed>  $then
     * @return list<array{index: int, operation: string, name: string}>
     */
    private function communities(array $then): array
    {
        $rows = [];

        foreach ((array) ($then['communities'] ?? []) as $community) {
            $rows[] = [
                'index' => count($rows),
                'operation' => (string) ($community['operation'] ?? ''),
                'name' => (string) ($community['name'] ?? ''),
            ];
        }

        return $rows === [] ? [['index' => 0, 'operation' => '', 'name' => '']] : $rows;
    }

    public function render(): View
    {
        return view('components.policy.then-fields');
    }
}
