<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchStormControl;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchStormControl>
 */
class SwitchStormControlFactory extends Factory
{
    protected $model = SwitchStormControl::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'interface_name' => 'all',
            'level' => 80,
            'bandwidth' => null,
            'no_broadcast' => false,
            'no_unknown_unicast' => false,
            'no_multicast' => false,
            'no_registered_multicast' => false,
            'no_unregistered_multicast' => false,
        ];
    }
}
