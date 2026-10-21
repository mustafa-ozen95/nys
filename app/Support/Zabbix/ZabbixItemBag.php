<?php

declare(strict_types=1);

namespace App\Support\Zabbix;

use Illuminate\Support\Collection;

/**
 * Bir hosta ait item listesini sorgulanabilir hale getirir.
 *
 * Zabbix item anahtarları şablona göre değişebildiği için arama önek
 * eşleşmesiyle yapılır; bu mantık job'larda tekrarlanmaz.
 */
final class ZabbixItemBag
{
    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function __construct(private Collection $items) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public static function fromApi(array $items): self
    {
        return new self(collect($items));
    }

    /**
     * Verilen öneklerden ilkiyle eşleşen item'ın son değeri.
     *
     * @param  array<int, string>|string  $prefixes
     */
    public function value(array|string $prefixes): ?string
    {
        $prefixes = (array) $prefixes;

        foreach ($prefixes as $prefix) {
            $item = $this->items->first(
                fn (array $item): bool => str_starts_with((string) ($item['key_'] ?? ''), $prefix)
            );

            if ($item !== null && ($item['lastvalue'] ?? '') !== '') {
                return (string) $item['lastvalue'];
            }
        }

        return null;
    }

    public function number(array|string $prefixes): ?float
    {
        $value = $this->value($prefixes);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Aynı önekten birden fazla item varsa en yüksek değer (örneğin çok
     * işlemcili şasilerde en sıcak kart).
     */
    public function max(string $prefix): ?float
    {
        $values = $this->items
            ->filter(fn (array $item): bool => str_starts_with((string) ($item['key_'] ?? ''), $prefix))
            ->pluck('lastvalue')
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value);

        return $values->isEmpty() ? null : $values->max();
    }

    /**
     * Önekle eşleşen item'ları SNMP ifIndex'e göre eşler.
     *
     * @return array<int, string>
     */
    public function byIfIndex(string $prefix): array
    {
        return $this->items
            ->filter(fn (array $item): bool => str_starts_with((string) ($item['key_'] ?? ''), $prefix))
            ->mapWithKeys(function (array $item): array {
                $index = ItemKey::ifIndex((string) $item['key_']);

                return $index === null ? [] : [$index => (string) ($item['lastvalue'] ?? '')];
            })
            ->all();
    }

    /**
     * Önekle eşleşen item'ların kimliklerini ifIndex'e göre eşler.
     *
     * Geçmiş veri (history.get) itemid ile sorgulandığı için gerekli.
     *
     * @return array<int, string>
     */
    public function itemIdsByIfIndex(string $prefix): array
    {
        return $this->items
            ->filter(fn (array $item): bool => str_starts_with((string) ($item['key_'] ?? ''), $prefix))
            ->mapWithKeys(function (array $item): array {
                $index = ItemKey::ifIndex((string) $item['key_']);

                return $index === null ? [] : [$index => (string) $item['itemid']];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->items->all();
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }
}
