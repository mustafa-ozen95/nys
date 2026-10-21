<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\SwitchSecureVlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchSecureVlan>
 */
class SwitchSecureVlanFactory extends Factory
{
    protected $model = SwitchSecureVlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'vlan_name' => 'all',
            'examine_dhcp' => true,
            'arp_inspection' => false,
            'ip_source_guard' => false,
            'mac_move_limit' => null,
            'mac_move_action' => null,
        ];
    }
}
