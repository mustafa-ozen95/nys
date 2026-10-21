<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchAsPath;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchAsPath>
 */
class SwitchAsPathFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'name' => 'as-path'.fake()->unique()->numberBetween(1, 9999),
            'path' => '.* '.fake()->numberBetween(64512, 65534),
        ];
    }
}
