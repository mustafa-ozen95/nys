<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\SwitchIpAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SwitchIpAddress
 */
class IpAddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'interface' => $this->interface_name,
            'address' => $this->address,
            'prefix_length' => $this->prefix_length,
            'family' => $this->family?->value,
        ];
    }
}
