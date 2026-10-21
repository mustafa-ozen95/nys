<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FirewallAction;
use App\Models\SwitchFirewallFilter;
use App\Models\SwitchFirewallTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchFirewallTerm>
 */
class SwitchFirewallTermFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_firewall_filter_id' => SwitchFirewallFilter::factory(),
            'name' => 'terim'.fake()->unique()->numberBetween(1, 9999),
            'position' => 0,
            'source_addresses' => [],
            'destination_addresses' => [],
            'source_prefix_lists' => [],
            'destination_prefix_lists' => [],
            'protocols' => [],
            'ports' => [],
            'source_ports' => [],
            'destination_ports' => [],
            'icmp_types' => [],
            'action' => FirewallAction::Accept,
            'count_name' => null,
            'is_logged' => false,
        ];
    }
}
