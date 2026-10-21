<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CredentialProtocol;
use App\Models\NetworkSwitch;
use App\Models\SwitchCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchCredential>
 */
class SwitchCredentialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'protocol' => CredentialProtocol::Ssh,
            'username' => 'admin',
            'password' => fake()->password(12, 20),
            'port' => 22,
        ];
    }

    public function snmp(): static
    {
        return $this->state(fn (): array => [
            'protocol' => CredentialProtocol::SnmpV2c,
            'username' => null,
            'password' => null,
            'community' => 'public',
            'port' => 161,
        ]);
    }
}
