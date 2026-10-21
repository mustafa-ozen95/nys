<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MailStatus;
use App\Models\AnnouncementMail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnnouncementMail>
 */
class AnnouncementMailFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recipient_label' => 'NOC',
            'recipient_email' => fake()->safeEmail(),
            'locale' => 'tr',
            'subject' => 'Prefix List Ekleme Talebi',
            'body' => 'Merhaba,',
            'prefixes' => '192.0.2.0/24',
            'asn' => 'AS64496',
            'status' => MailStatus::Queued,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (): array => ['status' => MailStatus::Sent, 'sent_at' => now()]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => MailStatus::Failed, 'error' => 'SMTP bağlantısı kurulamadı.']);
    }
}
