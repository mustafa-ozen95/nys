<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchRstpInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchRstpInterface>
 */
class SwitchRstpInterfaceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'interface_name' => 'ge-0/0/'.fake()->unique()->numberBetween(0, 23),
            'edge' => false,
            'no_root_port' => false,
            'cost' => null,
            'priority' => null,
        ];
    }
}
