<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use Illuminate\Support\Facades\DB;

/**
 * Fırtına denetimi ayarlarını cihazdan envantere yazar.
 *
 * Budama KOŞULSUZDUR: cihazda hiç ayar kalmaması gerçek bir durumdur (son
 * satır silinmiştir) ve envanterde hayalet kayıt bırakmak, satırdan
 * "Düzenle" demenin ayarı cihazda yeniden yaratması demektir.
 */
class StormControlSynchronizer
{
    public function __construct(private StormControlReader $reader) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        ['shutdown' => $shutdown, 'interfaces' => $interfaces] = $this->reader->stormControl($session, $switch);

        DB::transaction(function () use ($switch, $shutdown, $interfaces): void {
            $switch->forceFill(['storm_control_shutdown' => $shutdown])->save();

            $kept = [];

            foreach ($interfaces as $interface) {
                $kept[] = $switch->stormControls()->updateOrCreate(
                    ['interface_name' => $interface['interface_name']],
                    collect($interface)->except('interface_name')->all(),
                )->id;
            }

            $switch->stormControls()->whereNotIn('id', $kept)->delete();
        });
    }
}
