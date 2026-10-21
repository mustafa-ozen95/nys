<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchFirewallPolicer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchFirewallPolicer>
 */
class SwitchFirewallPolicerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'name' => 'policer'.fake()->unique()->numberBetween(1, 9999),
            'filter_specific' => false,
            'bandwidth_limit' => '1m',
            'bandwidth_percent' => null,
            'burst_size_limit' => '15k',
            'is_discard' => true,
            'forwarding_class' => null,
            'loss_priority' => null,
            'residual_xml' => null,
        ];
    }
}
