<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConnectionProtocol;
use App\Enums\ServerOs;
use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'short_name' => 'LNX-'.fake()->unique()->numberBetween(10, 99),
            'long_name' => fake()->words(2, true),
            'ip_address' => fake()->unique()->ipv4(),
            'os_type' => ServerOs::Linux,
            'os_version' => 'Ubuntu 24.04 LTS',
            'connection_protocol' => ConnectionProtocol::Ssh,
            'connection_port' => 22,
            'connection_username' => 'root',
            'connection_password' => fake()->password(12, 24),
            'status' => ServerStatus::Online,
        ];
    }

    public function windows(): static
    {
        return $this->state(fn (): array => [
            'short_name' => 'WIN-'.fake()->unique()->numberBetween(10, 99),
            'os_type' => ServerOs::Windows,
            'os_version' => 'Windows Server 2022',
            'connection_protocol' => ConnectionProtocol::Rdp,
            'connection_port' => 3389,
            'connection_username' => 'administrator',
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn (): array => ['status' => ServerStatus::Offline]);
    }
}
