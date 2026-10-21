<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IpFamily;
use App\Enums\RouteProtocol;
use App\Models\NetworkSwitch;
use App\Models\SwitchRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchRoute>
 */
class SwitchRouteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'destination' => fake()->unique()->ipv4(),
            'prefix_length' => fake()->randomElement([24, 25, 26, 32]),
            'next_hop' => fake()->ipv4(),
            'protocol' => RouteProtocol::Static,
            'family' => IpFamily::Inet,
        ];
    }
}
