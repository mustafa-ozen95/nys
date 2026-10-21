<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchPrefixList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchPrefixList>
 */
class SwitchPrefixListFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'name' => fake()->unique()->randomElement(['koru', 'RE_FILTER', 'ME_FILTER', 'mgmt', 'peering']),
        ];
    }
}
