<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bir cihazın parola değiştirme turunun sonucu.
 */
enum PasswordRotationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Sırada',
            self::Running => 'Sürüyor',
            self::Succeeded => 'Tamamlandı',
            self::Failed => 'Başarısız',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Pending => BadgeVariant::Gray,
            self::Running => BadgeVariant::Blue,
            self::Succeeded => BadgeVariant::Green,
            self::Failed => BadgeVariant::Red,
        };
    }

    public function isFinished(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
