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
        $settings = $this->reader->read($session);

        DB::transaction(function () use ($switch, $settings): void {
            $switch->forceFill([
                'snmp_location' => $settings['snmp_location'],
                'snmp_contact' => $settings['snmp_contact'],
                'time_zone' => $settings['time_zone'],
                'name_servers' => $settings['name_servers'],
                'ntp_servers' => $settings['ntp_servers'],
                'ntp_boot_server' => $settings['ntp_boot_server'],
                'lldp_interfaces' => $settings['lldp_interfaces'],
                'lldp_med_interfaces' => $settings['lldp_med_interfaces'],
                'igmp_snooping_vlans' => $settings['igmp_snooping_vlans'],
                'syslog_hosts' => $settings['syslog_hosts'],
                'rstp_configured' => $settings['rstp']['configured'],
                'rstp_enabled' => $settings['rstp']['enabled'],
                'rstp_bridge_priority' => $settings['rstp']['bridge_priority'],
                'rstp_bpdu_block_on_edge' => $settings['rstp']['bpdu_block_on_edge'],
                'alarm_ethernet_link_down' => $settings['alarms']['ethernet'],
                'alarm_management_link_down' => $settings['alarms']['management'],
            ])->save();

            $this->storeCommunities($switch, $settings['communities']);
            $this->storeLoginUsers($switch, $settings['login_users']);
            $this->storeRstpInterfaces($switch, $settings['rstp']['interfaces']);
        });
    }

    /**
     * @param  list<array{name: string, authorization: string, clients: list<string>}>  $communities
     */
    private function storeCommunities(NetworkSwitch $switch, array $communities): void
    {
        $kept = [];

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

            $kept[] = $model->id;
        }

        $switch->snmpCommunities()->whereNotIn('id', $kept)->delete();
    }

    /**
     * @param  list<array{name: string, full_name: ?string, uid: ?int, class: string, has_password: bool}>  $users
     */
    private function storeLoginUsers(NetworkSwitch $switch, array $users): void
    {
        $kept = [];

        foreach ($users as $user) {
            $kept[] = $switch->loginUsers()->updateOrCreate(
                ['name' => $user['name']],
                [
                    'full_name' => $user['full_name'],
                    'uid' => $user['uid'],
                    'class' => $user['class'],
                    'has_password' => $user['has_password'],
                ],
            )->id;
        }

        $switch->loginUsers()->whereNotIn('id', $kept)->delete();
    }

    /**
     * @param  list<array{interface_name: string, edge: bool, no_root_port: bool, cost: ?int, priority: ?int}>  $interfaces
     */
    private function storeRstpInterfaces(NetworkSwitch $switch, array $interfaces): void
    {
        $kept = [];

        foreach ($interfaces as $interface) {
            $kept[] = $switch->rstpInterfaces()->updateOrCreate(
                ['interface_name' => $interface['interface_name']],
                [
                    'edge' => $interface['edge'],
                    'no_root_port' => $interface['no_root_port'],
                    'cost' => $interface['cost'],
                    'priority' => $interface['priority'],
                ],
            )->id;
        }

        $switch->rstpInterfaces()->whereNotIn('id', $kept)->delete();
    }
}
