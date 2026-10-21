<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Vlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vlan
 */
class VlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'tag' => $this->vlan_tag,
            'description' => $this->description,
        ];
    }
}
