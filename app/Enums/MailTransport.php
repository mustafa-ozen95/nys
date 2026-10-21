<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Panelin e-postayı nasıl göndereceği.
 *
 * "Günlüğe yaz" bir hata ayıklama kipidir ve SESSİZDİR: mail gönderilmiş
 * gibi görünür, aslında storage/logs içine yazılır. Üretimde bu kipte
 * kalmak (MAIL_MAILER=log), prefix anons taleplerinin hiç gitmemesi
 * demektir.
 */
enum MailTransport: string
{
    case Smtp = 'smtp';
    case Log = 'log';

    public function label(): string
    {
        return match ($this) {
            self::Smtp => 'SMTP ile gönder',
            self::Log => 'Gönderme, günlüğe yaz',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Smtp => BadgeVariant::Green,
            // Sarı bilinçli: bu kipte hiçbir mail gitmiyor.
            self::Log => BadgeVariant::Yellow,
        };
    }
}
