<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Services\Snmp\IpAddressReader;
use App\Services\Snmp\SnmpException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Switch arayüzlerindeki IP adreslerini cihazdan okur.
 *
 * SNMP ile yalnızca okuma. Cihaz yanıt vermezse mevcut kayıtlar korunur.
 */
class SyncSwitchIpAddressesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * Kilidin en fazla ne kadar tutulacağı.
     *
     * Bu OLMADAN kilit iş bitene kadar durur; iş bir şekilde kaybolursa
     * (yanlış kuyruk, düşen işçi) kilit sonsuza kadar kalır ve sonraki her
     * dispatch SESSİZCE atılır. Eşitleme yarım saatte bir koştuğu için
     * kilit ondan önce düşmeli.
     */
    public int $uniqueFor = 1500;

    public function __construct(public int $switchId)
    {
        $this->onQueue(config('snmp.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->switchId;
    }

    public function handle(IpAddressReader $reader): void
    {
        $switch = NetworkSwitch::query()->with('ports')->find($this->switchId);

        if ($switch === null || $switch->snmpCredential === null) {
            return;
        }

        /*
         * NETCONF varsa bu iş çalışmaz: adresler yapılandırmadan okunuyor ve
         * yazma yolu da oradan okuduğu için tek sahip olmalı. Bu yol yalnızca
         * NETCONF kimliği tanımlı olmayan cihazlar için kalır.
         */
        if ($switch->netconfCredential !== null) {
            return;
        }

        try {
            $addresses = $reader->addresses($switch);
        } catch (SnmpException $exception) {
            Log::warning("IP adresleri okunamadı: {$switch->hostname}", ['hata' => $exception->getMessage()]);

            return;
        }

        if ($addresses === []) {
            return;
        }

        $portsByIfIndex = $switch->ports->keyBy('if_index');

        // Adresler çoğunlukla VLAN'ın L3 arayüzündedir (vlan.25); VLAN
        // kayıtlarında bu ad l3_interface olarak duruyor.
        $vlansByInterface = $switch->vlans()
            ->whereNotNull('l3_interface')
            ->get()
            ->keyBy('l3_interface');

        DB::transaction(function () use ($switch, $addresses, $portsByIfIndex, $vlansByInterface): void {
            foreach ($addresses as $address) {
                $interface = $address['interface']
                    ?? $portsByIfIndex->get($address['if_index'])?->name
                    ?? "ifIndex {$address['if_index']}";

                $vlan = $vlansByInterface->get($interface);

                $switch->ipAddresses()->updateOrCreate(
                    ['address' => $address['address']],
                    [
                        'prefix_length' => $address['prefix_length'],
                        'interface_name' => $interface,
                        'vlan_id' => $vlan?->id,
                        // VLAN arayüzündeki adres bir porta bağlanmaz.
                        'switch_port_id' => $vlan !== null
                            ? null
                            : $portsByIfIndex->get($address['if_index'])?->id,
                    ],
                );
            }

            $switch->ipAddresses()
                ->whereNotIn('address', array_column($addresses, 'address'))
                ->delete();
        });
    }

    /**
     * @param  Collection<int, SwitchPort>  $ports
     */
    private function interfaceName($ports, int $ifIndex): string
    {
        return $ports->get($ifIndex)?->name ?? "ifIndex {$ifIndex}";
    }
}
