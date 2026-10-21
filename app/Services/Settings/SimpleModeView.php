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
    public function bolumler(array $group, array $values): array
    {
        $ogeler = collect($group['items'])->keyBy('key');
        $bolumler = [];

        foreach (config('simple_mode.bolumler') as $bolum => $_) {
            $gor = $ogeler["simple_mode.bolumler.{$bolum}.goruntule"] ?? null;
            $yon = $ogeler["simple_mode.bolumler.{$bolum}.yonet"] ?? null;

            if ($gor === null || $yon === null) {
                continue;
            }

            $alanlar = collect(config("simple_mode.alanlar.{$bolum}", []))
                ->map(fn ($tanim, $alan) => ['item' => $ogeler["simple_mode.alanlar.{$bolum}.{$alan}.acik"] ?? null, 'varsayilan' => (bool) $tanim['varsayilan']])
                ->filter(fn ($a) => $a['item'] !== null);

            $bolumler[] = [
                'ad' => config("permissions.resources.{$bolum}.label", $bolum),
                'anahtarlar' => [
                    $this->kutu($gor, $values, 'Görüntülenir'),
                    $this->kutu($yon, $values, 'Yönetilir'),
                ],
                'alanlar' => $alanlar->map(fn ($a) => $this->kutu($a['item'], $values, $a['item']->label))->values()->all(),
                'farkli' => $alanlar->filter(fn ($a) => (bool) $values[$a['item']->key] !== $a['varsayilan'])->count(),
            ];
        }

        return $bolumler;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{ad: string, acik: bool, etiket: string, ipucu: ?string}
     */
    private function kutu(SettingDefinition $item, array $values, string $etiket): array
    {
        return [
            'ad' => $item->field(),
            'acik' => (bool) old($item->field(), $values[$item->key]),
            'etiket' => $etiket,
            'ipucu' => $item->hint,
        ];
    }
}
