<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AdminStatus;
use App\Enums\Duplex;
use App\Enums\PortMode;
use App\Enums\PortStatus;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchPort>
 */
class SwitchPortFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'name' => 'ge-0/0/'.fake()->unique()->numberBetween(0, 47),
            'if_index' => fake()->unique()->numberBetween(500, 999),
            'description' => fake()->optional()->words(3, true),
            'admin_status' => AdminStatus::Up,
            'oper_status' => PortStatus::Up,
            'speed_config' => null,
            'is_autonegotiation_enabled' => true,
            'speed_mbps' => fake()->randomElement([100, 1000, 10000]),
            'duplex' => Duplex::Full,
            'is_poe_enabled' => false,
            'port_mode' => PortMode::Access,
            'last_polled_at' => now(),
        ];
    }

    public function down(): static
    {
        return $this->state(fn (): array => ['oper_status' => PortStatus::Down]);
    }

    public function trunk(): static
    {
        return $this->state(fn (): array => ['port_mode' => PortMode::Trunk]);
    }
}
