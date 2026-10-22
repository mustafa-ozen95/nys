<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use Illuminate\Support\Facades\DB;

/**
 * VoIP VLAN ayarlarını cihazdan envantere yazar.
 *
 * Budama KOŞULSUZDUR: cihazda hiç ayar kalmaması gerçek bir durumdur (son
 * satır silinmiştir) ve envanterde hayalet kayıt bırakmak, satırdan
 * "Düzenle" demenin ayarı cihazda yeniden yaratması demektir.
 */
class VoipSynchronizer
{
    public function __construct(private VoipReader $reader) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        $interfaces = $this->reader->voip($session, $switch);

        DB::transaction(function () use ($switch, $interfaces): void {
            $kept = [];

            foreach ($interfaces as $interface) {
                $kept[] = $switch->voipInterfaces()->updateOrCreate(
                    ['interface_name' => $interface['interface_name']],
                    collect($interface)->except('interface_name')->all(),
                )->id;
            }

            $switch->voipInterfaces()->whereNotIn('id', $kept)->delete();
        });
    }
}
