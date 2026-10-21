<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchVoipInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchVoipInterface>
 */
class SwitchVoipInterfaceFactory extends Factory
{
    protected $model = SwitchVoipInterface::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'interface_name' => 'ge-0/0/5.0',
            'vlan_name' => 'ses',
            'forwarding_class' => null,
        ];
    }
}
