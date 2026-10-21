<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Models\Vlan;
use Illuminate\Support\Facades\DB;

/**
 * Arayüz adreslerini cihazdan envantere yazar.
 *
 * Adresin bağlı olduğu nesne arayüz adından çözülür: "vlan.100" o L3
 * arayüzüne sahip VLAN'a, "ge-0/0/23.0" ise porta bağlanır. Çözülemezse
 * adres yine kaydedilir, yalnızca bağı boş kalır — cihazda duran bir adres
 * envanterden düşmemeli.
 */
class IpAddressSynchronizer
{
    public function __construct(private IpAddressReader $reader) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        ['interfaces' => $interfaces, 'addresses' => $addresses] = $this->reader->addresses($session);

        /*
         * Cihaz hiç arayüz döndürmediyse yanıt kullanılamaz; envanter
         * bozulmaz. Arayüz var ama adres yoksa bu GERÇEK bir durumdur —
         * son adres silinmiştir — ve aşağıdaki budama onu envanterden de
         * düşürür. İkisi ayrılmasaydı son adres silindiğinde panelde hayalet
         * bir kayıt kalırdı.
         */
        if ($interfaces === 0) {
            return;
        }

        $ports = $switch->ports()->pluck('id', 'name');
        $vlans = $switch->vlans()->whereNotNull('l3_interface')->pluck('id', 'l3_interface');

        DB::transaction(function () use ($switch, $addresses, $ports, $vlans): void {
            $tutulan = [];

            foreach ($addresses as $address) {
                $tutulan[] = $switch->ipAddresses()->updateOrCreate(
                    [
                        'interface_name' => $address['interface_name'],
                        'address' => $address['address'],
                    ],
                    [
                        'prefix_length' => $address['prefix_length'],
                        'family' => $address['family'],
                        'vlan_id' => $vlans[$address['interface_name']] ?? null,
                        'switch_port_id' => $ports[$this->physicalName($address['interface_name'])] ?? null,
                    ],
                )->id;
            }

            $switch->ipAddresses()->whereNotIn('id', $tutulan)->delete();
        });
    }

    /**
     * "ge-0/0/23.0" arayüzünün taşıyıcı portu "ge-0/0/23"tür.
     */
    private function physicalName(string $interfaceName): string
    {
        return (string) preg_replace('/\.\d+$/', '', $interfaceName);
    }
}
