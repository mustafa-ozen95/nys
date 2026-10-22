<?php

declare(strict_types=1);

namespace App\Services\Snmp;

use App\Models\NetworkSwitch;

/**
 * Switchin VLAN'ları ve port üyelikleri.
 *
 * Zincir: jnxExVlanPortGroup(vlanIdx, bridgePort)
 *         -> dot1dBasePortIfIndex(bridgePort) = mantıksal ifIndex
 *         -> ifStack(mantıksal) = fiziksel ifIndex
 *         -> panelin sakladığı port
 */
class VlanReader
{
    /**
     * jnxExVlanPortTagness: etiketsiz üyeliği gösteren değer.
     */
    private const UNTAGGED = 2;

    public function __construct(private SnmpClient $snmp) {}

    /**
     * @return array<int, array{tag: int, name: string, l3_interface: ?string}>
     *                                                                          Juniper iç indeksine göre
     */
    public function vlans(NetworkSwitch $switch): array
    {
        $oids = config('snmp.oids');

        $names = $this->snmp->walk($switch, $oids['vlan_name']);
        $tags = $this->snmp->walk($switch, $oids['vlan_tag']);
        $l3 = $this->snmp->walk($switch, $oids['vlan_l3_interface']);

        $vlans = [];

        foreach ($tags as $index => $tag) {
            if (! is_numeric($tag) || (int) $tag < 1) {
                continue;
            }

            $vlans[(int) $index] = [
                'tag' => (int) $tag,
                'name' => $names[$index] ?? "VLAN {$tag}",
                'l3_interface' => ($l3[$index] ?? '') ?: null,
            ];
        }

        return $vlans;
    }

    /**
     * Port üyelikleri: fiziksel ifIndex => [vlan iç indeksi => etiketsiz mi].
     *
     * @return array<int, array<int, bool>>
     */
    public function memberships(NetworkSwitch $switch): array
    {
        $oids = config('snmp.oids');

        $tagness = $this->snmp->walk($switch, $oids['vlan_port_tagness']);
        $physical = $this->physicalPorts($switch);

        $memberships = [];

        foreach ($tagness as $index => $value) {
            // İndeks: <vlanIdx>.<bridgePort>
            [$vlanIndex, $bridgePort] = array_pad(explode('.', $index, 2), 2, null);

            $ifIndex = $physical[(int) $bridgePort] ?? null;

            if ($ifIndex === null) {
                continue;
            }

            $memberships[$ifIndex][(int) $vlanIndex] = (int) $value === self::UNTAGGED;
        }

        return $memberships;
    }

    /**
     * Portun etiketsiz VLAN etiketi: fiziksel ifIndex => VLAN etiketi.
     *
     * @return array<int, int>
     */
    public function nativeVlans(NetworkSwitch $switch): array
    {
        $physical = $this->physicalPorts($switch);
        $native = [];

        foreach ($this->snmp->walk($switch, config('snmp.oids.port_pvid')) as $bridgePort => $tag) {
            $ifIndex = $physical[(int) $bridgePort] ?? null;

            if ($ifIndex !== null && is_numeric($tag) && (int) $tag > 0) {
                $native[$ifIndex] = (int) $tag;
            }
        }

        return $native;
    }

    /**
     * Bridge port numarasından fiziksel ifIndex'e.
     *
     * Bridge tablosu mantıksal arayüzü gösterir (ge-0/0/1.0); panel fiziksel
     * portu tutar (ge-0/0/1). Çeviri ifStackTable ile yapılır.
     *
     * @return array<int, int>
     */
    private function physicalPorts(NetworkSwitch $switch): array
    {
        $oids = config('snmp.oids');

        $bridge = $this->snmp->walk($switch, $oids['bridge_port_ifindex']);
        $stack = $this->snmp->walk($switch, $oids['if_stack']);

        // ifStack indeksi <ust>.<alt>; üstü 0 olan satırlar taban arayüzdür.
        $below = [];

        foreach (array_keys($stack) as $index) {
            [$upper, $alt] = array_pad(explode('.', $index, 2), 2, null);

            if ((int) $upper > 0 && (int) $alt > 0) {
                $below[(int) $upper] = (int) $alt;
            }
        }

        $ports = [];

        foreach ($bridge as $bridgePort => $logical) {
            $ifIndex = (int) $logical;

            $ports[(int) $bridgePort] = $below[$ifIndex] ?? $ifIndex;
        }

        return $ports;
    }
}
