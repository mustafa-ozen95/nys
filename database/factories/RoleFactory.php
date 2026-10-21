<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BadgeVariant;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'slug' => Str::slug($label),
            'label' => Str::title($label),
            'badge' => BadgeVariant::Gray,
            'is_admin' => false,
            'is_system' => false,
            'template' => [],
            'position' => 10,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['is_admin' => true, 'badge' => BadgeVariant::Purple]);
    }

    /** Yerleşik rol: silinemez, slug'ı değişmez. */
    public function system(): static
    {
        return $this->state(fn (): array => ['is_system' => true]);
    }
}
