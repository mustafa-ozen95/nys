<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BgpGroupType;
use App\Models\BgpGroup;
use App\Models\NetworkSwitch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BgpGroup>
 */
class BgpGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            // Junos grup adı harfle başlar.
            'name' => 'grup'.fake()->unique()->numberBetween(1, 9999),
            'type' => BgpGroupType::External,
            'peer_asn' => (string) fake()->numberBetween(64512, 65534),
            'local_asn' => null,
            'local_address' => fake()->ipv4(),
            'multihop' => false,
            'multihop_ttl' => null,
            'log_updown' => true,
            'passive' => false,
            'import_policies' => [],
            'export_policies' => [],
            'description' => null,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn (): array => [
            'type' => BgpGroupType::Internal,
            'peer_asn' => null,
        ]);
    }

    public function multihop(int $ttl = 5): static
    {
        return $this->state(fn (): array => ['multihop' => true, 'multihop_ttl' => $ttl]);
    }
}
