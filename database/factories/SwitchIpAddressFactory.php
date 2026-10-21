<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IpFamily;
use App\Models\NetworkSwitch;
use App\Models\SwitchIpAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchIpAddress>
 */
class SwitchIpAddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'switch_port_id' => null,
            'interface_name' => 'ge-0/0/'.fake()->numberBetween(0, 47),
            'address' => fake()->unique()->ipv4(),
            'prefix_length' => 24,
            'family' => IpFamily::Inet,
        ];
    }
}
