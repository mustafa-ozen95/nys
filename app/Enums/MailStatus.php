<?php

declare(strict_types=1);

namespace App\Enums;

enum MailStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Kuyrukta',
            self::Sent => 'Gönderildi',
            self::Failed => 'Başarısız',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Queued => BadgeVariant::Yellow,
            self::Sent => BadgeVariant::Green,
            self::Failed => BadgeVariant::Red,
        };
    }
}
