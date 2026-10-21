<?php

declare(strict_types=1);

namespace App\Services\Zabbix;

use Illuminate\Support\Collection;

/**
 * Zabbix geçmiş verisini okur.
 *
 * Kısa aralıklarda ham geçmiş (history.get), uzun aralıklarda saatlik özet
 * (trend.get) kullanılır: Zabbix ham veriyi sınırlı süre saklar ve uzun
 * aralıkta ham sorgu hem yavaş hem gereksiz ayrıntılıdır.
 */
class ZabbixHistoryReader
{
    /**
     * Sayaç tipi item'lar Zabbix'te "numeric unsigned"dır.
     */
    private const VALUE_TYPE_UNSIGNED = 3;

    public function __construct(private ZabbixClient $client) {}

    /**
     * @param  array<int, string>  $itemIds
     * @return Collection<int, array{clock: int, itemid: string, value: float}>
     */
    public function series(array $itemIds, int $from, int $to): Collection
    {
        if ($itemIds === []) {
            return collect();
        }

        return $this->usesTrends($from, $to)
            ? $this->trends($itemIds, $from, $to)
            : $this->history($itemIds, $from, $to);
    }

    public function usesTrends(int $from, int $to): bool
    {
        return ($to - $from) > (int) config('zabbix.chart_trend_threshold');
    }

    /**
     * @param  array<int, string>  $itemIds
     * @return Collection<int, array{clock: int, itemid: string, value: float}>
     */
    private function history(array $itemIds, int $from, int $to): Collection
    {
        $rows = $this->client->call('history.get', [
            'output' => 'extend',
            'history' => self::VALUE_TYPE_UNSIGNED,
            'itemids' => array_values($itemIds),
            'time_from' => $from,
            'time_till' => $to,
            'sortfield' => 'clock',
            'sortorder' => 'ASC',
        ]);

        return $this->normalise($rows, 'value');
    }

    /**
     * @param  array<int, string>  $itemIds
     * @return Collection<int, array{clock: int, itemid: string, value: float}>
     */
    private function trends(array $itemIds, int $from, int $to): Collection
    {
        $rows = $this->client->call('trend.get', [
            'output' => ['itemid', 'clock', 'value_avg'],
            'itemids' => array_values($itemIds),
            'time_from' => $from,
            'time_till' => $to,
        ]);

        return $this->normalise($rows, 'value_avg')->sortBy('clock')->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, array{clock: int, itemid: string, value: float}>
     */
    private function normalise(array $rows, string $valueKey): Collection
    {
        return collect($rows)->map(fn (array $row): array => [
            'clock' => (int) $row['clock'],
            'itemid' => (string) $row['itemid'],
            'value' => (float) ($row[$valueKey] ?? 0),
        ]);
    }
}
