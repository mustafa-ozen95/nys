<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\FirewallAction;

/**
 * Hangi prefix list'in hangi arayüzün ÇIKIŞINDA bekçilik ettiğini bulur.
 *
 * Tipik yapı: uplink çıkışına bir filtre bağlanır ve filtre "kaynağı şu
 * prefix list'te olanı kabul et, kalanı düşür" der. Sonuç, bir bloğun o
 * listede olmadan o uplinkten ÇIKAMAMASIDIR — anons listesine girmiş olması
 * yetmez.
 *
 * Bilgi elle işaretlenmez, ÇIKARILIR; cihazda filtre değişirse panel
 * kendiliğinden düzelir.
 *
 * Ayrıştırma FirewallReader'dadır: aynı XML'i iki kez çözümlemek, iki
 * çözümlemenin zamanla ayrışması demektir.
 */
class EgressGuardReader
{
    public function __construct(private FirewallReader $firewall) {}

    /**
     * Prefix list adı => çıkışında bekçilik ettiği arayüzler.
     *
     * @return array<string, list<string>>
     *
     * @throws NetconfException
     */
    public function guards(NetconfSession $session): array
    {
        $guards = [];

        foreach ($this->firewall->filters($session) as $filter) {
            $interfaces = $this->outputInterfaces($filter['bindings']);

            // Hiçbir arayüze bağlı olmayan filtre kimseyi korumuyordur.
            if ($interfaces === []) {
                continue;
            }

            foreach ($filter['terms'] as $term) {
                /*
                 * Yalnızca KABUL terimindeki liste bekçidir. Reddeden bir
                 * terimde geçen liste kara listedir; oraya blok eklemek tam
                 * ters etki yapardı.
                 */
                if ($term['action'] !== FirewallAction::Accept) {
                    continue;
                }

                foreach ($term['source_prefix_lists'] as $list) {
                    $guards[$list] = [...($guards[$list] ?? []), ...$interfaces];
                }
            }
        }

        return array_map(
            static fn (array $interfaces): array => array_values(array_unique($interfaces)),
            $guards,
        );
    }

    /**
     * @param  list<array{interface: string, direction: string}>  $bindings
     * @return list<string>
     */
    private function outputInterfaces(array $bindings): array
    {
        return array_values(array_map(
            static fn (array $binding): string => $binding['interface'],
            array_filter($bindings, static fn (array $binding): bool => $binding['direction'] === 'output'),
        ));
    }
}
