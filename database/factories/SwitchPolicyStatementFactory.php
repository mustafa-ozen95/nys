<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchPolicyStatement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchPolicyStatement>
 */
class SwitchPolicyStatementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'name' => 'politika'.fake()->unique()->numberBetween(1, 9999),
            'is_manageable' => true,
        ];
    }

    public function unmanageable(): static
    {
        return $this->state(fn (): array => ['is_manageable' => false]);
    }
}
