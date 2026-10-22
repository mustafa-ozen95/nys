<?php

declare(strict_types=1);

namespace App\Services\Settings;

/**
 * Ayarlar → Basit mod ekranının hazır verisi.
 *
 * Bölüm başına iki ana anahtar (görüntüle / yönet) ve varsa alan kutuları
 * burada kurulur; şablon yalnızca bu listeyi çizer. Kutunun işaretli olup
 * olmadığı önce eski girdiden (doğrulama hatası sonrası), yoksa kayıtlı
 * değerden okunur.
 */
class SimpleModeView
{
    /**
     * @param  array{items: iterable<int, SettingDefinition>}  $group
     * @param  array<string, mixed>  $values
     * @return array<int, array{
     *     ad: string,
     *     anahtarlar: array<int, array{ad: string, acik: bool, etiket: string, ipucu: ?string}>,
     *     alanlar: array<int, array{ad: string, acik: bool, etiket: string}>,
     *     farkli: int
     * }>
     */
    public function sections(array $group, array $values): array
    {
        $items = collect($group['items'])->keyBy('key');
        $sections = [];

        foreach (config('simple_mode.bolumler') as $section => $_) {
            $see = $items["simple_mode.bolumler.{$section}.goruntule"] ?? null;
            $direction = $items["simple_mode.bolumler.{$section}.yonet"] ?? null;

            if ($see === null || $direction === null) {
                continue;
            }

            $fields = collect(config("simple_mode.alanlar.{$section}", []))
                ->map(fn ($definition, $alan) => ['item' => $items["simple_mode.alanlar.{$section}.{$alan}.acik"] ?? null, 'varsayilan' => (bool) $definition['varsayilan']])
                ->filter(fn ($a) => $a['item'] !== null);

            $sections[] = [
                'ad' => config("permissions.resources.{$section}.label", $section),
                'anahtarlar' => [
                    $this->box($see, $values, 'Görüntülenir'),
                    $this->box($direction, $values, 'Yönetilir'),
                ],
                'alanlar' => $fields->map(fn ($a) => $this->box($a['item'], $values, $a['item']->label))->values()->all(),
                'farkli' => $fields->filter(fn ($a) => (bool) $values[$a['item']->key] !== $a['varsayilan'])->count(),
            ];
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{ad: string, acik: bool, etiket: string, ipucu: ?string}
     */
    private function box(SettingDefinition $item, array $values, string $tag): array
    {
        return [
            'ad' => $item->field(),
            'acik' => (bool) old($item->field(), $values[$item->key]),
            'etiket' => $tag,
            'ipucu' => $item->hint,
        ];
    }
}
