<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchRibGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchRibGroup>
 */
class SwitchRibGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'name' => 'grup-'.fake()->unique()->numberBetween(1, 9999),
            'import_ribs' => ['inet.0'],
            'residual_xml' => null,
        ];
    }
}
