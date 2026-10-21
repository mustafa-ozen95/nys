<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SMTP bağlantısının şifreleme biçimi (Symfony Mailer DSN şeması).
 *
 * "smtp" sunucu destekliyorsa STARTTLS'e yükselir, desteklemiyorsa düz
 * devam eder — 25 ve 587 için doğru olan budur. "smtps" ise bağlantıyı
 * baştan TLS ile açar; 465 bunu ister ve STARTTLS ile açılmaya çalışılırsa
 * el sıkışma hiç tamamlanmaz.
 */
enum MailScheme: string
{
    case Smtp = 'smtp';
    case Smtps = 'smtps';

    public function label(): string
    {
        return match ($this) {
            self::Smtp => 'STARTTLS (587, 25)',
            self::Smtps => 'Doğrudan TLS (465)',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Smtp => BadgeVariant::Blue,
            self::Smtps => BadgeVariant::Green,
        };
    }
}
