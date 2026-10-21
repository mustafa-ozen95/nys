<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SwitchLocation;
use App\Enums\SwitchRole;
use App\Enums\SwitchStatus;
use App\Models\NetworkSwitch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkSwitch>
 */
class NetworkSwitchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'hostname' => 'K'.fake()->unique()->numberBetween(1, 999),
            'management_ip' => fake()->unique()->ipv4(),
            'uplink_ip' => fake()->ipv4(),
            'model' => 'Juniper EX'.fake()->randomElement([2200, 3400, 4300]),
            'junos_version' => '21.4R3-S'.fake()->numberBetween(1, 9),
            'role' => SwitchRole::Access,
            'location' => SwitchLocation::Plus,
            'status' => SwitchStatus::Online,
            // Gerçek eşleşme Zabbix'ten çözülür; uydurma kimlik sync'i sessizce boşa düşürür.
            'zabbix_host_id' => null,
            'uptime_seconds' => fake()->numberBetween(3600, 30_000_000),
            'cpu_usage' => fake()->numberBetween(1, 95),
            'memory_usage' => fake()->numberBetween(10, 95),
            'temperature' => fake()->numberBetween(25, 65),
            'last_sync_at' => now(),
        ];
    }

    public function core(): static
    {
        return $this->state(fn (): array => ['role' => SwitchRole::Core]);
    }

    public function zabbixHost(string $hostId): static
    {
        return $this->state(fn (): array => ['zabbix_host_id' => $hostId]);
    }

    public function offline(): static
    {
        return $this->state(fn (): array => [
            'status' => SwitchStatus::Offline,
            'cpu_usage' => null,
            'memory_usage' => null,
            'temperature' => null,
        ]);
    }
}
