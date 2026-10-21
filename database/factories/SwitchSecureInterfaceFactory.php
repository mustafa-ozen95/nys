<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchSecureInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchSecureInterface>
 */
class SwitchSecureInterfaceFactory extends Factory
{
    protected $model = SwitchSecureInterface::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'interface_name' => 'ge-0/0/5',
            'dhcp_trusted' => null,
            'mac_limit' => null,
            'mac_limit_action' => null,
            'allowed_macs' => null,
        ];
    }
}
