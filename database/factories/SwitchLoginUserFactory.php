<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\JunosUserClass;
use App\Models\NetworkSwitch;
use App\Models\SwitchLoginUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchLoginUser>
 */
class SwitchLoginUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'name' => 'hesap'.fake()->unique()->numberBetween(1, 9999),
            'full_name' => fake()->name(),
            'uid' => fake()->unique()->numberBetween(2000, 60000),
            'class' => JunosUserClass::ReadOnly->value,
            'has_password' => true,
        ];
    }

    /** Panelin cihaza bağlanmak için kullandığı hesap. */
    public function panel(): static
    {
        return $this->state(fn (): array => [
            'name' => 'admin',
            'class' => JunosUserClass::SuperUser->value,
        ]);
    }
}
