<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kuyruğa alınmış bir cihaz yazmasının durumu.
 *
 * "Değişiklik yok" ayrı bir durumdur, başarı değil: cihaz zaten istenen
 * hâldeydi ve commit ATILMADI. İkisini aynı saymak, kullanıcıya yapılmamış
 * bir değişikliği yapılmış gibi göstermek olurdu.
 */
enum SwitchWriteStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Unchanged = 'unchanged';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Sırada',
            self::Running => 'Cihaza yazılıyor',
            self::Completed => 'Cihaza yazıldı',
            self::Unchanged => 'Değişiklik yok',
            self::Failed => 'Başarısız',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Queued => BadgeVariant::Gray,
            self::Running => BadgeVariant::Cyan,
            self::Completed => BadgeVariant::Green,
            self::Unchanged => BadgeVariant::Gray,
            self::Failed => BadgeVariant::Red,
        };
    }

    /**
     * İş bitti mi? Ekran yoklamayı buna bakarak durdurur.
     */
    public function isFinished(): bool
    {
        return match ($this) {
            self::Queued, self::Running => false,
            default => true,
        };
    }
}
