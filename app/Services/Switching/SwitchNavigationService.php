<?php

declare(strict_types=1);

namespace App\Services\Switching;

use App\Models\NetworkSwitch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Sütunlu gezinmenin ilk iki sütununu üretir: switch listesi ve seçili switchin
 * nesneleri. Her ekran bunu yeniden hesaplamaz.
 */
class SwitchNavigationService
{
    /**
     * @return Collection<int, NetworkSwitch>
     */
    public function switches(User $user): Collection
    {
        return NetworkSwitch::query()
            ->visibleTo($user)
            ->orderByRole()
            ->orderBySeverity()
            ->orderBy('hostname')
            ->get();
    }

    /**
     * Seçili switchin altındaki nesne kümeleri.
     *
     * Kullanıcının GÖREMEDİĞİ nesne listelenmez: her nesnenin kendi
     * görüntüleme yetkisi vardır ve yetkisi olmayan biri hem bağlantıyı
     * görmemeli hem de tıklasa 403 almalı. Görsel önlem tek başına yeterli
     * değildir, ikisi birlikte durur.
     *
     * Genel bakış ve commit geçmişi switchin kendi görüntüleme yetkisine
     * bağlıdır; ayrı bir nesne değillerdir.
     *
     * @return array<int, array{label: string, url: ?string, count: ?int, active: bool}>
     */
    public function objects(NetworkSwitch $switch, string $current = ''): array
    {
        // Bütün sayaçlar tek sorguda alınır; her sekme için ayrı count atılmaz.
        $switch->loadCount(['ports', 'vlans', 'ipAddresses', 'routes', 'prefixLists', 'policyStatements', 'firewallFilters', 'bgpNeighbors', 'commits', 'aggregates', 'rstpInterfaces', 'voipInterfaces', 'analyzers', 'secureVlans', 'secureInterfaces', 'routingInstances', 'ribGroups']);

        $objects = [
            [
                'label' => 'Genel bakış',
                'url' => route('switches.show', $switch),
                'count' => null,
                'active' => $current === 'overview',
            ],
            [
                'label' => 'Portlar',
                'ability' => 'viewSwitchPorts',
                'url' => route('switches.ports', $switch),
                'count' => $switch->ports_count,
                'active' => $current === 'ports',
            ],
            [
                'label' => 'Bağlantı toplama',
                'ability' => 'viewSwitchAggregation',
                'url' => route('switches.aggregation', $switch),
                'count' => $switch->aggregates_count,
                'active' => $current === 'aggregation',
            ],
            [
                'label' => 'VLAN’lar',
                'ability' => 'viewSwitchVlans',
                'url' => route('switches.vlans', $switch),
                'count' => $switch->vlans_count,
                'active' => $current === 'vlans',
            ],
            [
                'label' => 'IP adresleri',
                'ability' => 'viewSwitchIpAddresses',
                'url' => route('switches.ip-addresses', $switch),
                'count' => $switch->ip_addresses_count,
                'active' => $current === 'ip-addresses',
            ],
            [
                'label' => 'Route’lar',
                'ability' => 'viewSwitchRoutes',
                'url' => route('switches.routes', $switch),
                'count' => $switch->routes_count,
                'active' => $current === 'routes',
            ],
            [
                'label' => 'Spanning tree',
                'ability' => 'viewSwitchRstp',
                'url' => route('switches.rstp', $switch),
                'count' => $switch->rstp_interfaces_count,
                'active' => $current === 'rstp',
            ],
            [
                'label' => 'VoIP VLAN',
                'ability' => 'viewSwitchVoip',
                'url' => route('switches.voip', $switch),
                'count' => $switch->voip_interfaces_count,
                'active' => $current === 'voip',
            ],
            [
                /*
                 * Port aynalama koruma değil TEŞHİS aracıdır ve kendi
                 * yetkisini taşır: aynalama kaynağın bütün trafiğini bir
                 * başka porta kopyalar.
                 */
                'label' => 'Port aynalama',
                'ability' => 'viewSwitchMirroring',
                'url' => route('switches.mirroring', $switch),
                'count' => $switch->analyzers_count,
                'active' => $current === 'mirroring',
            ],
            [
                /*
                 * DHCP snooping ve port güvenliği tek ekrandır: VLAN kuralı
                 * korumayı açar, arayüz kuralı hangi portun güvenildiğini
                 * söyler. Ayrı sekmelerde olsalardı biri açılıp diğeri
                 * unutulurdu; sayaç da bu yüzden ikisinin toplamıdır.
                 */
                'label' => 'DHCP snooping',
                'ability' => 'viewSwitchSecureAccess',
                'url' => route('switches.secure-access', $switch),
                'count' => $switch->secure_vlans_count + $switch->secure_interfaces_count,
                'active' => $current === 'secure-access',
            ],
            [
                'label' => 'Prefix listeler',
                'ability' => 'viewSwitchPrefixLists',
                'url' => route('switches.prefix-lists', $switch),
                'count' => $switch->prefix_lists_count,
                'active' => $current === 'prefix-lists',
            ],
            /*
             * FBF üç parçadan oluşuyor ve ikisi burada. Üçüncüsü (firewall
             * terimi) hemen altta duruyor: sıra bilinçli, kullanıcı önce
             * tabloyu kurup sonra trafiği ona atıyor.
             */
            [
                'label' => 'Filtre yönlendirme',
                'ability' => 'viewSwitchForwarding',
                'url' => route('switches.forwarding', $switch),
                'count' => $switch->routing_instances_count + $switch->rib_groups_count,
                'active' => $current === 'forwarding',
            ],
            [
                'label' => 'Firewall',
                'ability' => 'viewSwitchFirewall',
                'url' => route('switches.firewall', $switch),
                'count' => $switch->firewall_filters_count,
                'active' => $current === 'firewall',
            ],
            [
                'label' => 'Politikalar',
                'ability' => 'viewSwitchPolicies',
                'url' => route('switches.policies', $switch),
                'count' => $switch->policy_statements_count,
                'active' => $current === 'policies',
            ],
            [
                'label' => 'BGP',
                'ability' => 'viewSwitchBgp',
                'url' => route('switches.bgp', $switch),
                'count' => $switch->bgp_neighbors_count,
                'active' => $current === 'bgp',
            ],
            [
                'label' => 'Ayarlar',

                // Ekranın tamamı cihaza yazma eylemidir; yazamayana gösterilmez.
                'ability' => 'configure',
                'url' => route('switches.settings', $switch),
                'count' => null,
                'active' => $current === 'settings',
            ],
            [
                'label' => 'Commit geçmişi',
                'url' => route('switches.commits', $switch),
                'count' => $switch->commits_count,
                'active' => $current === 'commits',
            ],
        ];

        return array_values(array_filter(
            $objects,
            static fn (array $object): bool => ! isset($object['ability'])
                || Gate::allows($object['ability'], $switch),
        ));
    }
}
