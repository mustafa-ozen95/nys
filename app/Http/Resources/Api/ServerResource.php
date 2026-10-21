<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Server
 */
class ServerResource extends JsonResource
{
    /**
     * Kimlik olarak UUID verilir; sıralı birincil anahtar dışarı ÇIKMAZ.
     *
     * BAĞLANTI PAROLASI HİÇBİR KOŞULDA YER ALMAZ ve kullanıcı adı da
     * vermiyoruz: ikisi birlikte cihaza giriş demek ve API jetonu sızarsa
     * sunucu erişimi de sızmış olurdu. Parola okumak, ayrı yetki isteyen ve
     * her okumayı kayda geçiren ayrı bir uçtan yapılır.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'short_name' => $this->short_name,
            'long_name' => $this->long_name,
            'ip_address' => $this->ip_address,
            'os_type' => $this->os_type?->value,
            'os_version' => $this->os_version,
            'connection_protocol' => $this->connection_protocol?->value,
            'connection_port' => $this->connection_port,
            'location' => $this->location,
            'status' => $this->status?->value,

            /*
             * Parolanın KENDİSİ değil, son turun ne zaman ve nasıl bittiği.
             * Kopyasını tutan bir istemci bununla kendi kopyasının eskiyip
             * eskimediğini anlayabilir.
             */
            'last_password_rotation' => $this->whenLoaded(
                'latestPasswordRotation',
                fn (): ?array => $this->latestPasswordRotation === null ? null : [
                    'status' => $this->latestPasswordRotation->status?->value,
                    'stage' => $this->latestPasswordRotation->stage?->value,
                    'finished_at' => $this->latestPasswordRotation->finished_at?->toIso8601String(),
                ],
            ),

            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
