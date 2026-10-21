<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Junos peer grubu türü.
 *
 * Junos yalnızca bu ikisini bilir; üçüncü bir tür yoktur. Ayrım kozmetik
 * değildir: internal grupta peer-as kendi AS'imizdir ve ayrıca sorulmaz,
 * external grupta zorunludur.
 */
enum BgpGroupType: string
{
    case External = 'external';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::External => 'eBGP (dış)',
            self::Internal => 'iBGP (iç)',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::External => BadgeVariant::Blue,
            self::Internal => BadgeVariant::Purple,
        };
    }

    /**
     * Karşı taraf farklı bir AS'te mi? peer-as yalnızca o zaman anlamlıdır.
     */
    public function requiresPeerAsn(): bool
    {
        return $this === self::External;
    }
}
