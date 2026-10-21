<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivityChannel;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'channel' => ActivityChannel::Crud,
            'action' => fake()->randomElement(['created', 'updated', 'deleted', 'viewed']),
            'description' => fake()->sentence(),
            'ip_address' => fake()->ipv4(),
        ];
    }
}
