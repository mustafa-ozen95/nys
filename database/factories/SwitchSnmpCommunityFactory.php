<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchSnmpCommunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchSnmpCommunity>
 */
class SwitchSnmpCommunityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'name' => 'community'.fake()->unique()->numberBetween(1, 9999),
            'authorization' => 'read-only',
            'clients' => [],
        ];
    }
}
