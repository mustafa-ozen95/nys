<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use Illuminate\Support\Facades\DB;

/**
 * Cihazdaki yönlendirme tablolarını envantere yazar.
 *
 * Kimlik ADIDIR: firewall terimi ve rib-group tanımları hep adla atıfta
 * bulunur, dolayısıyla eşleştirme de adla yapılır.
 */
class RoutingInstanceSynchronizer
{
    public function __construct(private RoutingInstanceReader $reader) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        $instances = $this->reader->instances($session);

        DB::transaction(function () use ($switch, $instances): void {
            foreach ($instances as $instance) {
                $switch->routingInstances()->updateOrCreate(
                    ['name' => $instance['name']],
                    [
                        'instance_type' => $instance['instance_type'],
                        'description' => $instance['description'],
                        'residual_xml' => $instance['residual_xml'],
                    ],
                );
            }

            /*
             * Cihazda kalmayan tablo envanterden de düşer. Budama koşulsuzdur:
             * boş liste "hiç tablo yok" demektir ve gerçek bir durumdur.
             */
            $switch->routingInstances()
                ->whereNotIn('name', array_column($instances, 'name'))
                ->delete();
        });
    }
}
