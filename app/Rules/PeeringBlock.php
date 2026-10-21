<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Net\Cidr;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * BGP komşuluğunun kurulduğu blok.
 *
 * Peering için ayrılan blok dardır (/30 tipik); geniş bir blok verilmesi
 * neredeyse her zaman yanlış giriştir.
 */
class PeeringBlock implements ValidationRule
{
    private const NARROWEST_PREFIX = 24;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cidr = Cidr::tryParse((string) $value);

        if ($cidr === null) {
            $fail('Geçersiz blok. Örnek: 10.0.0.0/30');

            return;
        }

        if ($cidr->isIpv6) {
            $fail('Peering bloğu şimdilik yalnızca IPv4 olabilir.');

            return;
        }

        if ($cidr->prefixLength < self::NARROWEST_PREFIX) {
            $fail('Peering bloğu en fazla /'.self::NARROWEST_PREFIX.' genişliğinde olabilir.');

            return;
        }

        if ($cidr->network !== explode('/', (string) $value)[0]) {
            $fail("Ağ adresi {$cidr->network} olmalı.");
        }
    }
}
