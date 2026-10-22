<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Models\SwitchFirewallFilter;
use Illuminate\Support\Facades\DB;

/**
 * Firewall filtrelerini ve policer'ları cihazdan envantere yazar.
 *
 * Kaynak cihazdır: panelde olup cihazda olmayan filtre silinir. Bir filtreyi
 * envanterde tutmak, olmayan bir korumayı var sanmak demektir.
 *
 * Panelin tanımadığı satırlar da envantere geçer (residual_xml): yazma yolu
 * filtreyi silip yeniden kurduğu için o satırlar buradan geri basılır.
 * Okunmasalardı sessizce kaybolurlardı.
 */
class FirewallSynchronizer
{
    /**
     * Terimin cihazdan gelen alanları. updateOrCreate'e "ad hariç her şey"
     * demek yerine liste tutulur: okuyucunun döndürdüğü "position" gibi
     * alanlar da yazılmalı ama modelde olmayan bir anahtar sessizce
     * atılmamalı.
     *
     * @var list<string>
     */
    private const TERM_FIELDS = [
        'position',
        'source_addresses', 'destination_addresses',
        'source_prefix_lists', 'source_prefix_list_excepts',
        'destination_prefix_lists', 'destination_prefix_list_excepts',
        'protocols', 'ports', 'source_ports', 'destination_ports',
        'icmp_types', 'icmp_codes', 'tcp_flags', 'is_tcp_initial', 'is_tcp_established',
        'dscps', 'precedences', 'packet_lengths', 'ttls', 'ip_options', 'fragment_flags',
        'is_fragment', 'is_first_fragment', 'interfaces',
        'next_headers', 'traffic_classes',
        'ether_types', 'source_mac_addresses', 'destination_mac_addresses',
        'action', 'goes_to_next_term', 'routing_instance', 'policer',
        'forwarding_class', 'loss_priority', 'dscp', 'analyzer',
        'is_port_mirrored', 'is_sampled', 'is_syslogged',
        'count_name', 'is_logged',
        'residual_xml', 'from_residual_xml', 'then_residual_xml',
        'is_inactive',
    ];

    /**
     * @var list<string>
     */
    private const POLICER_FIELDS = [
        'filter_specific', 'bandwidth_limit', 'bandwidth_percent', 'burst_size_limit',
        'is_discard', 'forwarding_class', 'loss_priority', 'residual_xml',
    ];

    public function __construct(private FirewallReader $reader) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        ['filters' => $filters, 'policers' => $policers] = $this->reader->read($session);

        DB::transaction(function () use ($switch, $filters, $policers): void {
            $kept = [];

            foreach ($filters as $filter) {
                $model = $switch->firewallFilters()->updateOrCreate(
                    ['family' => $filter['family'], 'name' => $filter['name']],
                    [
                        'interface_specific' => $filter['interface_specific'],
                        'is_manageable' => $filter['is_manageable'],
                        'bindings' => $filter['bindings'],
                        'residual_xml' => $filter['residual_xml'],
                        'is_inactive' => $filter['is_inactive'],
                    ],
                );

                $kept[] = $model->id;

                $this->storeTerms($model, $filter['terms']);
            }

            $switch->firewallFilters()->whereNotIn('id', $kept)->delete();

            $this->storePolicers($switch, $policers);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $terms
     */
    private function storeTerms(SwitchFirewallFilter $filter, array $terms): void
    {
        $kept = [];

        foreach ($terms as $term) {
            $kept[] = $filter->terms()->updateOrCreate(
                ['name' => $term['name']],
                collect($term)->only(self::TERM_FIELDS)->all(),
            )->id;
        }

        $filter->terms()->whereNotIn('id', $kept)->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $policers
     */
    private function storePolicers(NetworkSwitch $switch, array $policers): void
    {
        $kept = [];

        foreach ($policers as $policer) {
            $kept[] = $switch->firewallPolicers()->updateOrCreate(
                ['name' => $policer['name']],
                collect($policer)->only(self::POLICER_FIELDS)->all(),
            )->id;
        }

        $switch->firewallPolicers()->whereNotIn('id', $kept)->delete();
    }
}
