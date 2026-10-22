<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use SimpleXMLElement;

/**
 * Cihazın kendi ayarları: sistem adı, saat, isim sunucuları, günlük hedefi,
 * SNMP kimliği ve community'ler, RSTP.
 *
 * Bunlar panelde YAZILABİLİR olduğu için OKUNMAK zorunda: yazılıp okunmayan
 * bir alan, bir sonraki güncellemede kullanıcının dokunmadığı değeri
 * silerdi. Terminalden yapılan değişiklik de ancak okunursa görünür.
 */
class DeviceSettingsReader
{
    /**
     * @return array{
     *     hostname: ?string,
     *     time_zone: ?string,
     *     name_servers: list<string>,
     *     ntp_servers: list<string>,
     *     ntp_boot_server: ?string,
     *     lldp_interfaces: ?list<string>,
     *     lldp_med_interfaces: ?list<string>,
     *     igmp_snooping_vlans: ?list<string>,
     *     syslog_hosts: list<string>,
     *     snmp_name: ?string,
     *     snmp_location: ?string,
     *     snmp_contact: ?string,
     *     communities: list<array{name: string, authorization: string, clients: list<string>}>,
     *     login_users: list<array{name: string, full_name: ?string, uid: ?int, class: string, has_password: bool}>,
     *     alarms: array{ethernet: ?string, management: ?string},
     *     rstp: array{configured: bool, enabled: bool, bridge_priority: ?string, bpdu_block_on_edge: bool, interfaces: list<array{interface_name: string, edge: bool, no_root_port: bool, cost: ?int, priority: ?int}>}
     * }
     *
     * @throws NetconfException
     */
    public function read(NetconfSession $session): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed"><configuration>'
            .'<system/><snmp/>'
            .'<protocols><rstp/><lldp/><lldp-med/><igmp-snooping/></protocols>'
            .'<chassis><alarm/></chassis>'
            .'</configuration></get-configuration>'
        );

        return [
            'hostname' => $this->text($reply, '//*[local-name()="system"]/*[local-name()="host-name"]'),
            'time_zone' => $this->text($reply, '//*[local-name()="system"]/*[local-name()="time-zone"]'),
            'name_servers' => $this->names($reply, '//*[local-name()="system"]/*[local-name()="name-server"]'),
            'ntp_servers' => $this->names($reply, '//*[local-name()="ntp"]/*[local-name()="server"]'),
            'ntp_boot_server' => $this->text($reply, '//*[local-name()="ntp"]/*[local-name()="boot-server"]'),
            'syslog_hosts' => $this->names($reply, '//*[local-name()="syslog"]/*[local-name()="host"]'),
            'snmp_name' => $this->snmpName($reply),
            'snmp_location' => $this->text($reply, '//*[local-name()="snmp"]/*[local-name()="location"]'),
            'snmp_contact' => $this->text($reply, '//*[local-name()="snmp"]/*[local-name()="contact"]'),
            'communities' => $this->communities($reply),
            'login_users' => $this->loginUsers($reply),
            'alarms' => [
                'ethernet' => $this->text($reply, '//*[local-name()="alarm"]/*[local-name()="ethernet"]/*[local-name()="link-down"]'),
                'management' => $this->text($reply, '//*[local-name()="alarm"]/*[local-name()="management-ethernet"]/*[local-name()="link-down"]'),
            ],
            'rstp' => $this->rstp($reply),

            /*
             * Protokol anahtarları. NULL = cihazda TANIMLI DEĞİL; boş liste
             * "tanımlı ama kapsamı yok" demek olurdu ve ikisi farklı şeydir.
             */
            'lldp_interfaces' => $this->scoped($reply, 'lldp', 'interface'),
            'lldp_med_interfaces' => $this->scoped($reply, 'lldp-med', 'interface'),
            'igmp_snooping_vlans' => $this->scoped($reply, 'igmp-snooping', 'vlan'),
        ];
    }

    /**
     * Cihaz sistem adını "name" ya da "system-name" olarak verebilir; ikisi
     * de aynı ifadedir (cihazda karşılaştırıldı, farkları aynı satırı
     * üretiyor).
     */
    private function snmpName(SimpleXMLElement $reply): ?string
    {
        return $this->text($reply, '//*[local-name()="snmp"]/*[local-name()="system-name"]')
            ?? $this->text($reply, '//*[local-name()="snmp"]/*[local-name()="name"]');
    }

    /**
     * Cihazdaki yerel hesaplar.
     *
     * PAROLA OKUNMAZ: cihazın tuttuğu özet panelde hiçbir işe yaramaz ve
     * yalnızca çalınabilecek bir sır daha olurdu. Yalnızca varlığı okunur.
     *
     * @return list<array{name: string, full_name: ?string, uid: ?int, class: string, has_password: bool}>
     */
    private function loginUsers(SimpleXMLElement $reply): array
    {
        $users = [];

        foreach ($reply->xpath('//*[local-name()="login"]/*[local-name()="user"]') ?: [] as $node) {
            $name = $this->child($node, 'name');

            if ($name === null) {
                continue;
            }

            $uid = $this->child($node, 'uid');

            $users[] = [
                'name' => $name,
                'full_name' => $this->child($node, 'full-name'),
                'uid' => $uid !== null && ctype_digit($uid) ? (int) $uid : null,
                'class' => $this->child($node, 'class') ?? 'unauthorized',
                'has_password' => ($node->xpath('./*[local-name()="authentication"]/*') ?: []) !== [],
            ];
        }

        return $users;
    }

    /**
     * @return list<array{name: string, authorization: string, clients: list<string>}>
     */
    private function communities(SimpleXMLElement $reply): array
    {
        $communities = [];

        foreach ($reply->xpath('//*[local-name()="snmp"]/*[local-name()="community"]') ?: [] as $node) {
            $name = $this->child($node, 'name');

            if ($name === null) {
                continue;
            }

            $communities[] = [
                'name' => $name,
                'authorization' => $this->child($node, 'authorization') ?? 'read-only',
                'clients' => $this->childNames($node, 'clients'),
            ];
        }

        return $communities;
    }

    /**
     * @return array{configured: bool, enabled: bool, bridge_priority: ?string, bpdu_block_on_edge: bool, interfaces: list<array{interface_name: string, edge: bool, no_root_port: bool, cost: ?int, priority: ?int}>}
     */
    private function rstp(SimpleXMLElement $reply): array
    {
        $nodes = $reply->xpath('//*[local-name()="protocols"]/*[local-name()="rstp"]') ?: [];
        $rstp = $nodes[0] ?? null;

        if ($rstp === null) {
            return [
                'configured' => false,
                'enabled' => true,
                'bridge_priority' => null,
                'bpdu_block_on_edge' => false,
                'interfaces' => [],
            ];
        }

        $interfaces = [];

        foreach ($rstp->xpath('./*[local-name()="interface"]') ?: [] as $node) {
            $name = $this->child($node, 'name');

            if ($name === null) {
                continue;
            }

            $cost = $this->child($node, 'cost');
            $priority = $this->child($node, 'priority');

            $interfaces[] = [
                'interface_name' => $name,
                'edge' => $this->has($node, 'edge'),
                'no_root_port' => $this->has($node, 'no-root-port'),
                'cost' => $cost !== null && ctype_digit($cost) ? (int) $cost : null,
                'priority' => $priority !== null && ctype_digit($priority) ? (int) $priority : null,
            ];
        }

        return [
            // "rstp" düğümü boş da olsa VARDIR: STP açık demektir.
            'configured' => true,
            'enabled' => ! $this->has($rstp, 'disable'),
            'bridge_priority' => $this->child($rstp, 'bridge-priority'),
            'bpdu_block_on_edge' => $this->has($rstp, 'bpdu-block-on-edge'),
            'interfaces' => $interfaces,
        ];
    }

    /**
     * @return list<string>
     */
    /**
     * Kapsamlı bir protokolün kapsamı: lldp/interface, igmp-snooping/vlan.
     *
     * NULL ile BOŞ LİSTE farklıdır ve ikisini karıştırmak cihazdaki satırı
     * sessizce silerdi:
     *
     *   null  -> protokol cihazda TANIMLI DEĞİL
     *   []    -> tanımlı ama kapsamı yok (Junos'ta mümkün)
     *
     * @return list<string>|null
     */
    private function scoped(SimpleXMLElement $reply, string $protocol, string $child): ?array
    {
        $root = $reply->xpath('//*[local-name()="protocols"]/*[local-name()="'.$protocol.'"]');

        if ($root === false || $root === []) {
            return null;
        }

        return $this->names(
            $reply,
            '//*[local-name()="protocols"]/*[local-name()="'.$protocol.'"]'
            .'/*[local-name()="'.$child.'"]',
        );
    }

    private function names(SimpleXMLElement $reply, string $path): array
    {
        $names = [];

        foreach ($reply->xpath($path) ?: [] as $node) {
            $name = $this->child($node, 'name');

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function childNames(SimpleXMLElement $node, string $element): array
    {
        $names = [];

        foreach ($node->xpath('./*[local-name()="'.$element.'"]') ?: [] as $child) {
            $name = $this->child($child, 'name');

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function has(SimpleXMLElement $node, string $element): bool
    {
        return ($node->xpath('./*[local-name()="'.$element.'"]') ?: []) !== [];
    }

    private function child(SimpleXMLElement $node, string $element): ?string
    {
        $found = $node->xpath('./*[local-name()="'.$element.'"]')[0] ?? null;

        return $found === null ? null : (trim((string) $found) ?: null);
    }

    private function text(SimpleXMLElement $reply, string $path): ?string
    {
        $found = $reply->xpath($path)[0] ?? null;

        return $found === null ? null : (trim((string) $found) ?: null);
    }
}
