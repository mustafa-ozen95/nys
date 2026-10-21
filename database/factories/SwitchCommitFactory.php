<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchCommit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchCommit>
 */
class SwitchCommitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'sequence_number' => fake()->unique()->numberBetween(0, 500),
            'committed_by' => fake()->randomElement(['admin', 'root', 'noc']),
            'comment' => fake()->optional()->sentence(),
            'committed_at' => fake()->dateTimeBetween('-1 year'),
        ];
    }
}
