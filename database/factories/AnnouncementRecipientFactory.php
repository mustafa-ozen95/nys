<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MailLocale;
use App\Models\AnnouncementRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnnouncementRecipient>
 */
class AnnouncementRecipientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'label' => fake()->unique()->word(),
            'email' => fake()->unique()->safeEmail(),
            'locale' => MailLocale::Tr,
            'is_active' => true,
        ];
    }

    public function english(): static
    {
        return $this->state(fn (): array => ['locale' => MailLocale::En]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
