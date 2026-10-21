<?php

declare(strict_types=1);

namespace App\Services\Zabbix;

use App\Support\Zabbix\ZabbixItemBag;

/**
 * Bir hosta ait item'ları okur.
 *
 * İki eniyileştirme burada durur:
 *
 *  1. Yalnızca KULLANDIĞIMIZ anahtarlar istenir. Hostun bütün item'ları
 *     çekilseydi küçük bir switch için bile yüzlerce satır gelir, çoğu
 *     atılırdı.
 *  2. Aynı istek içinde birden çok host okunacaksa tek çağrı yapılır ve
 *     sonuç istek boyunca hatırlanır. Switch başına ayrı çağrı, switch
 *     sayısı kadar gidiş dönüş demektir.
 *
 * Hatırlama isteğe/işe özgüdür, paylaşılan bir önbellek DEĞİLDİR: durum işi
 * otuz saniyede bir çalışıyorken beş dakikalık bayat veriyle karar vermemeli.
 */
class ZabbixItemReader
{
    /** @var array<string, ZabbixItemBag> host kimliği => item'lar */
    private array $memo = [];

    public function __construct(private ZabbixClient $client) {}

    public function forHost(string $hostId): ZabbixItemBag
    {
        return $this->memo[$hostId] ??= ZabbixItemBag::fromApi($this->fetch([$hostId]));
    }

    /**
     * Birden çok hostu tek çağrıda okur ve her birini hatırlar.
     *
     * @param  list<string>  $hostIds
     */
    public function forHosts(array $hostIds): void
    {
        $missing = array_values(array_diff($hostIds, array_keys($this->memo)));

        if ($missing === []) {
            return;
        }

        $grouped = [];

        foreach ($this->fetch($missing) as $item) {
            /*
             * hostid istenmesine rağmen gelmeyebilir (eski sürüm, kısıtlı
             * yetki). Tek host okunuyorsa cevabın sahibi bellidir; birden
             * fazlaysa satır atlanır ve o host tek tek okunur.
             */
            $hostId = isset($item['hostid']) ? (string) $item['hostid'] : (count($missing) === 1 ? $missing[0] : null);

            if ($hostId !== null) {
                $grouped[$hostId][] = $item;
            }
        }

        foreach ($missing as $hostId) {
            // Hiç satır gelmeyen host hatırlanmaz: boş bir cevabı hatırlamak,
            // o hostu istek boyunca "metriksiz" saymak olurdu.
            if (isset($grouped[$hostId])) {
                $this->memo[$hostId] = ZabbixItemBag::fromApi($grouped[$hostId]);
            }
        }
    }

    /**
     * @param  list<string>  $hostIds
     * @return array<int, array<string, mixed>>
     */
    private function fetch(array $hostIds): array
    {
        return $this->client->call('item.get', [
            'output' => ['itemid', 'hostid', 'key_', 'name', 'lastvalue', 'units', 'value_type'],
            'hostids' => $hostIds,
            'webitems' => true,

            /*
             * startSearch: eşleşme anahtarın BAŞINDAN yapılır — okuyucunun
             * kendi önek eşleşmesiyle aynı kural. searchByAny: listedeki
             * öneklerden herhangi biri yeterlidir.
             */
            'search' => ['key_' => $this->keys()],
            'startSearch' => true,
            'searchByAny' => true,
        ]);
    }

    /**
     * Panelin kullandığı item anahtarı önekleri. Listenin kendisi config'te
     * durur; burada düzleştirilir. Bir anahtar orada yoksa zaten hiçbir yerde
     * okunmuyordur.
     *
     * @return list<string>
     */
    private function keys(): array
    {
        $keys = [];

        foreach ((array) config('zabbix.items') as $value) {
            foreach ((array) $value as $prefix) {
                $keys[] = $prefix;
            }
        }

        return array_values(array_unique($keys));
    }
}
