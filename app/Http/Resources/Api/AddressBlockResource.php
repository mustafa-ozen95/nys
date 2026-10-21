<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\AddressBlock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AddressBlock
 */
class AddressBlockResource extends JsonResource
{
    /**
     * Adresleme bloğu.
     *
     * "atanabilir mi" ALANI ÖNEMLİ: bir blok bir erişim switchine route
     * edildiğinde artık başka hiçbir yere atanamaz ve panelde de listelerde
     * görünmez. Dışarıdan bakan bir istemcinin bunu bilmeden bir adres
     * seçmesi, panelin tam da önlemeye çalıştığı çakışmayı üretirdi.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'network' => $this->network,
            'prefix_length' => $this->prefix_length,
            'cidr' => $this->network.'/'.$this->prefix_length,
            'family' => $this->family,
            'purpose' => $this->purpose,
            'description' => $this->description,

            /* Alt ağlara bölünmüş blok kendisi adres vermez, çocukları verir. */
            'is_subnetted' => (bool) $this->is_subnetted,

            /*
             * Nereye route edilmiş. Doluysa blok TÜKENMİŞTİR: yalnızca o
             * cihazın kendi listelerinde görünür ve ikinci bir cihazdan
             * route edilemez.
             */
            'routed_to' => $this->when(
                $this->connection_type !== null,
                fn (): array => [
                    'connection_type' => $this->connection_type,
                    'access_switch' => $this->accessSwitch?->hostname,
                    'core_switch' => $this->coreSwitch?->hostname,
                    'vlan' => $this->vlan?->name,
                ],
            ),

            'assignable' => $this->connection_type === null && ! $this->is_subnetted,

            'subnets_count' => $this->whenCounted('subnets'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
