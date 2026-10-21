<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\SwitchPort;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SwitchPort
 */
class PortResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'mode' => $this->port_mode?->value,
            'admin_status' => $this->admin_status?->value,
            'oper_status' => $this->oper_status?->value,
            'speed_mbps' => $this->speed_mbps,
            'duplex' => $this->duplex?->value,
            'mtu' => $this->mtu,
            'vlans' => $this->whenLoaded('vlans', fn () => $this->vlans->pluck('name')->all()),
        ];
    }
}
