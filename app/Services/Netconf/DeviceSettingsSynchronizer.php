<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Models\SwitchSnmpCommunity;
use Illuminate\Support\Facades\DB;

/**
 * Cihazın kendi ayarlarını envantere yazar.
 *
 * Kaynak daima cihazdır: panelde olup cihazda olmayan community ya da RSTP
 * arayüzü silinir. Panel niyeti değil, cihazın gerçeğini gösterir.
 */
class DeviceSettingsSynchronizer
{
    public function __construct(private DeviceSettingsReader $reader) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        $ayarlar = $this->reader->read($session);

        DB::transaction(function () use ($switch, $ayarlar): void {
            $switch->forceFill([
                'snmp_location' => $ayarlar['snmp_location'],
                'snmp_contact' => $ayarlar['snmp_contact'],
                'time_zone' => $ayarlar['time_zone'],
                'name_servers' => $ayarlar['name_servers'],
                'ntp_servers' => $ayarlar['ntp_servers'],
                'ntp_boot_server' => $ayarlar['ntp_boot_server'],
                'lldp_interfaces' => $ayarlar['lldp_interfaces'],
                'lldp_med_interfaces' => $ayarlar['lldp_med_interfaces'],
                'igmp_snooping_vlans' => $ayarlar['igmp_snooping_vlans'],
                'syslog_hosts' => $ayarlar['syslog_hosts'],
                'rstp_configured' => $ayarlar['rstp']['configured'],
                'rstp_enabled' => $ayarlar['rstp']['enabled'],
                'rstp_bridge_priority' => $ayarlar['rstp']['bridge_priority'],
                'rstp_bpdu_block_on_edge' => $ayarlar['rstp']['bpdu_block_on_edge'],
                'alarm_ethernet_link_down' => $ayarlar['alarms']['ethernet'],
                'alarm_management_link_down' => $ayarlar['alarms']['management'],
            ])->save();

            $this->storeCommunities($switch, $ayarlar['communities']);
            $this->storeLoginUsers($switch, $ayarlar['login_users']);
            $this->storeRstpInterfaces($switch, $ayarlar['rstp']['interfaces']);
        });
    }

    /**
     * @param  list<array{name: string, authorization: string, clients: list<string>}>  $communities
     */
    private function storeCommunities(NetworkSwitch $switch, array $communities): void
    {
        $tutulan = [];

        foreach ($communities as $community) {
            /*
             * Ad şifreli saklandığı için doğrudan aranamaz; özeti üzerinden
             * bulunur (bkz. SwitchSnmpCommunity).
             */
            $model = $switch->snmpCommunities()->firstOrNew([
                'name_digest' => SwitchSnmpCommunity::digest($community['name']),
            ]);

            $model->fill([
                'name' => $community['name'],
                'authorization' => $community['authorization'],
                'clients' => $community['clients'],
            ])->save();

            $tutulan[] = $model->id;
        }

        $switch->snmpCommunities()->whereNotIn('id', $tutulan)->delete();
    }

    /**
     * @param  list<array{name: string, full_name: ?string, uid: ?int, class: string, has_password: bool}>  $users
     */
    private function storeLoginUsers(NetworkSwitch $switch, array $users): void
    {
        $tutulan = [];

        foreach ($users as $user) {
            $tutulan[] = $switch->loginUsers()->updateOrCreate(
                ['name' => $user['name']],
                [
                    'full_name' => $user['full_name'],
                    'uid' => $user['uid'],
                    'class' => $user['class'],
                    'has_password' => $user['has_password'],
                ],
            )->id;
        }

        $switch->loginUsers()->whereNotIn('id', $tutulan)->delete();
    }

    /**
     * @param  list<array{interface_name: string, edge: bool, no_root_port: bool, cost: ?int, priority: ?int}>  $interfaces
     */
    private function storeRstpInterfaces(NetworkSwitch $switch, array $interfaces): void
    {
        $tutulan = [];

        foreach ($interfaces as $interface) {
            $tutulan[] = $switch->rstpInterfaces()->updateOrCreate(
                ['interface_name' => $interface['interface_name']],
                [
                    'edge' => $interface['edge'],
                    'no_root_port' => $interface['no_root_port'],
                    'cost' => $interface['cost'],
                    'priority' => $interface['priority'],
                ],
            )->id;
        }

        $switch->rstpInterfaces()->whereNotIn('id', $tutulan)->delete();
    }
}
