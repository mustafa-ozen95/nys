<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PermissionEffect;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPermission>
 */
class UserPermissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'resource' => 'switches',
            'resource_id' => UserPermission::ALL_RECORDS,
            'action' => 'view',
            'effect' => PermissionEffect::Allow,
        ];
    }

    public function deny(): static
    {
        return $this->state(fn (): array => ['effect' => PermissionEffect::Deny]);
    }

    public function forRecord(int $id): static
    {
        return $this->state(fn (): array => ['resource_id' => $id]);
    }

    public function resource(string $resource, string $action = 'view'): static
    {
        return $this->state(fn (): array => ['resource' => $resource, 'action' => $action]);
    }
}
