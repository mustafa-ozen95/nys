<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use Illuminate\Support\Facades\DB;

/**
 * RIB gruplarını ve arayüz yolu işaretçisini envantere yazar.
 */
class RibGroupSynchronizer
{
    public function __construct(private RibGroupReader $reader) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        ['groups' => $groups, 'interface_routes' => $interfaceRoutes] = $this->reader->read($session);

        DB::transaction(function () use ($switch, $groups, $interfaceRoutes): void {
            foreach ($groups as $group) {
                $switch->ribGroups()->updateOrCreate(
                    ['name' => $group['name']],
                    [
                        'import_ribs' => $group['import_ribs'],
                        'residual_xml' => $group['residual_xml'],
                    ],
                );
            }

            $switch->ribGroups()
                ->whereNotIn('name', array_column($groups, 'name'))
                ->delete();

            /*
             * İşaretçi switch kaydında durur ve CİHAZDAN gelen değerle
             * güncellenir; envanterde tutulan eski bir ad, cihazda silinmiş
             * bir gruba işaret etmeye devam ederdi.
             */
            $switch->update(['interface_routes_rib_group' => $interfaceRoutes]);
        });
    }
}
