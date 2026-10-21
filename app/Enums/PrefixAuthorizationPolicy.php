<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Announcement\AuthorityVerdict;

/**
 * Kaç kaynağın onayı gönderim için yeter?
 *
 * Varsayılan kural: bir blok, kaynaklardan EN AZ BİRİ onaylıyorsa
 * gönderilebilir; gönderimi durduran şey HİÇBİR kaynağın onaylamamasıdır.
 *
 * Gerekçe: ROA ve IRR kayıtları çoğu zaman birbirini tamamlar; bir bloğun
 * yalnız birinde kaydı olabilir. "İkisi birden" şartı, gerçekten bize ait
 * olan blokları sırf bir kayıt eksik diye durdururdu. Gerçekten başkasına
 * ait blok ise ikisinde birden düşer (ör. 203.0.113.0/24 hem ROA'da hem
 * IRR'de AS64510'u gösterir).
 */
enum PrefixAuthorizationPolicy: string
{
    case Any = 'any';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Any => 'En az bir kaynak',
            self::All => 'Bütün kaynaklar',
        };
    }

    /**
     * Her enum etiketiyle birlikte bir renk taşır (EnumContractTest).
     * Ölçüt bir DURUM değil bir AYAR olduğu için renkler bilgilendiricidir:
     * gevşek olan mavi, katı olan mor.
     */
    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Any => BadgeVariant::Blue,
            self::All => BadgeVariant::Purple,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Any => 'Bir blok, kaynaklardan en az biri onaylıyorsa gönderilir; hiçbiri onaylamıyorsa durur.',
            self::All => 'Bir blok ancak bütün kaynaklar onaylıyorsa gönderilir.',
        };
    }

    /**
     * @param  array<int, AuthorityVerdict>  $verdicts
     */
    public function satisfiedBy(array $verdicts): bool
    {
        if ($verdicts === []) {
            // Hiç karar yoksa kanıt da yok: fail-closed.
            return false;
        }

        $approving = array_filter($verdicts, fn (AuthorityVerdict $verdict): bool => $verdict->approves());

        return match ($this) {
            self::Any => $approving !== [],
            self::All => count($approving) === count($verdicts),
        };
    }
}
