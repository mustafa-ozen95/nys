<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchAnalyzer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchAnalyzer>
 */
class SwitchAnalyzerFactory extends Factory
{
    protected $model = SwitchAnalyzer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'name' => 'aynalama',
            'ratio' => null,
            'loss_priority' => null,
            'output_interface' => 'ge-0/0/21.0',
            'output_vlan' => null,
            'ingress_interfaces' => ['ge-0/0/5.0'],
            'ingress_vlans' => null,
            'egress_interfaces' => null,
            'egress_vlans' => null,
        ];
    }
}
