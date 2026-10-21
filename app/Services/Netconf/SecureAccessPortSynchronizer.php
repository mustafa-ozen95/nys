<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * DHCP snooping ve port güvenliği ayarlarını cihazdan envantere yazar.
 *
 * Budama KOŞULSUZDUR: cihazda hiç kural kalmaması gerçek bir durumdur (son
 * satır silinmiştir) ve envanterde hayalet kayıt bırakmak, satırdan
 * "Düzenle" demenin kuralı cihazda yeniden yaratması demektir.
 */
class SecureAccessPortSynchronizer
{
    public function __construct(private SecureAccessPortReader $reader) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        ['vlans' => $vlans, 'interfaces' => $interfaces] = $this->reader->secureAccessPort($session, $switch);

        DB::transaction(function () use ($switch, $vlans, $interfaces): void {
            $this->store(fn (): HasMany => $switch->secureVlans(), $vlans, 'vlan_name');
            $this->store(fn (): HasMany => $switch->secureInterfaces(), $interfaces, 'interface_name');
        });
    }

    /**
     * İlişki her çağrıda YENİDEN kurulur: Eloquent'te bir ilişki nesnesine
     * eklenen kısıtlar üzerinde birikir ve aynı nesneyle hem güncelleme hem
     * budama yapmak, budamayı son güncellenen satıra daraltırdı.
     *
     * @param  Closure(): HasMany<covariant \Illuminate\Database\Eloquent\Model, NetworkSwitch>  $relation
     * @param  list<array<string, mixed>>  $rows
     */
    private function store(Closure $relation, array $rows, string $key): void
    {
        $tutulan = [];

        foreach ($rows as $row) {
            $tutulan[] = $relation()->updateOrCreate(
                [$key => $row[$key]],
                collect($row)->except($key)->all(),
            )->id;
        }

        $relation()->whereNotIn('id', $tutulan)->delete();
    }
}
