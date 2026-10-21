<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\SwitchRoute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SwitchRoute
 */
class RouteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'destination' => $this->destination.'/'.$this->prefix_length,
            'next_hop' => $this->next_hop,
            'next_hops' => $this->next_hops,
            'rib' => $this->rib,
            'routing_instance' => $this->routing_instance,
            'preference' => $this->preference,
            'metric' => $this->metric,
            'tag' => $this->tag,
        ];
    }
}
