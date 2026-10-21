<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\NetworkSwitch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NetworkSwitch
 */
class SwitchResource extends JsonResource
{
    /**
     * Kimlik olarak UUID verilir; sıralı birincil anahtar dışarı ÇIKMAZ.
     *
     * Kimlik bilgileri (parola, community) hiçbir koşulda yer almaz: API
     * jetonu sızarsa cihaz erişimi de sızmış olurdu.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'hostname' => $this->hostname,
            'management_ip' => $this->management_ip,
            'uplink_ip' => $this->uplink_ip,
            'role' => $this->role?->value,
            'location' => $this->location?->value,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'junos_version' => $this->junos_version,
            'status' => $this->status?->value,
            'uptime_seconds' => $this->uptime_seconds,
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'ports_count' => $this->whenCounted('ports'),
            'vlans_count' => $this->whenCounted('vlans'),
        ];
    }
}
