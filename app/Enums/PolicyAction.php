<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bir politika teriminin (ya da politikanın kendisinin) SONLANDIRICI eylemi.
 *
 * Junos'ta bunlar değerlendirmeyi bitiren ya da devreden eylemlerdir; diğer
 * eylemler (local-preference, metric, community...) rotayı DEĞİŞTİRİR ama
 * değerlendirmeyi bitirmez ve ayrı alanlarda tutulur (bkz. PolicyThen).
 *
 * "next policy" değerlendirmeyi bir sonraki politikaya, "next term" bir
 * sonraki terime devreder; accept ve reject zinciri orada bitirir.
 *
 * XML biçimi: Junos'ta "next" bir ARGÜMAN alır (`then next policy;`), yani
 * XML'de `<next>policy</next>` olur — `<next-policy/>` diye bir ifade Junos
 * sözdiziminde yoktur. Panel yalnızca `<next>policy</next>` yazar; okuyucu
 * cihazda `<next-policy/>` biçimiyle kalmış satırları da tanır.
 */
enum PolicyAction: string
{
    case Accept = 'accept';
    case Reject = 'reject';
    case NextPolicy = 'next-policy';
    case NextTerm = 'next-term';

    public function label(): string
    {
        return match ($this) {
            self::Accept => 'Kabul et',
            self::Reject => 'Reddet',
            self::NextPolicy => 'Sonraki politikaya geç',
            self::NextTerm => 'Sonraki terime geç',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Accept => BadgeVariant::Green,
            self::Reject => BadgeVariant::Red,
            self::NextPolicy, self::NextTerm => BadgeVariant::Gray,
        };
    }

    /**
     * Junos'un "next" eylemine verilen argüman; accept/reject için yoktur.
     */
    public function nextArgument(): ?string
    {
        return match ($this) {
            self::NextPolicy => 'policy',
            self::NextTerm => 'term',
            default => null,
        };
    }
}
