<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoutingInstanceType;
use App\Models\NetworkSwitch;
use App\Models\SwitchRoutingInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchRoutingInstance>
 */
class SwitchRoutingInstanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'name' => 'tablo-'.fake()->unique()->numberBetween(1, 9999),
            'instance_type' => RoutingInstanceType::Forwarding,
            'description' => null,
            'residual_xml' => null,
        ];
    }
}
