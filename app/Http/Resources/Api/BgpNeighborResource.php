<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\BgpNeighbor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BgpNeighbor
 *
 * OTURUM ANAHTARI (authentication_key) HİÇBİR KOŞULDA YER ALMAZ. Model onu
 * zaten `$hidden` tutuyor ama burada alanlar TEK TEK yazılıyor; gizli listeye
 * güvenip "hepsini dök" demek, yarın eklenen bir sırrı sessizce dışarı
 * vermenin yoluydu.
 */
class BgpNeighborResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'address' => $this->address,
            'description' => $this->description,
            'peer_asn' => $this->peer_asn,
            'group' => $this->whenLoaded('group', fn (): ?string => $this->group?->name),

            /*
             * Durum CİHAZDAN okunur, kullanıcıdan değil: panelin eski
             * sürümünde kullanıcının yazdığı bir durum cihazın gerçeği gibi
             * gösteriliyordu.
             */
            'state' => $this->state?->value,
            'flap_count' => $this->flap_count,
            'state_changed_seconds' => $this->state_changed_seconds,
        ];
    }
}
