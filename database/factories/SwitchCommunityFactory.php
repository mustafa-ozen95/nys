<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchCommunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchCommunity>
 */
class SwitchCommunityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'name' => 'community'.fake()->unique()->numberBetween(1, 9999),
            'members' => ['65000:'.fake()->numberBetween(1, 999)],
        ];
    }
}
