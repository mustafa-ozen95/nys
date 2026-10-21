<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BgpState;
use App\Models\BgpGroup;
use App\Models\BgpNeighbor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BgpNeighbor>
 */
class BgpNeighborFactory extends Factory
{
    public function definition(): array
    {
        $group = BgpGroup::factory();

        return [
            'bgp_group_id' => $group,
            // Komşu ile grubu aynı switche ait olmak zorunda; ikisi ayrı
            // switche düşerse cihazda karşılığı olmayan bir kayıt doğar.
            'switch_id' => fn (array $attributes): int => BgpGroup::find($attributes['bgp_group_id'])->switch_id,
            'address' => fake()->unique()->ipv4(),
            'description' => null,
            'peer_asn' => null,
            'local_address' => null,
            'state' => BgpState::Unknown,
        ];
    }

    public function established(): static
    {
        return $this->state(fn (): array => [
            'state' => BgpState::Established,
            'active_prefixes' => 0,
            'received_prefixes' => 0,
            'accepted_prefixes' => 0,
            'flap_count' => 0,
            'state_changed_seconds' => 3600,
        ]);
    }

    public function down(): static
    {
        return $this->state(fn (): array => ['state' => BgpState::Idle]);
    }
}
