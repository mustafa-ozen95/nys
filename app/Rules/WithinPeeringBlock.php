<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Net\Cidr;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Komşuluğun iki ucu da peering bloğunun kullanılabilir adresleri arasında
 * olmalı; ağ ve yayın adresi seçilemez.
 */
class WithinPeeringBlock implements ValidationRule
{
    public function __construct(private ?Cidr $block) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->block === null) {
            return;
        }

        $address = ip2long((string) $value);

        if ($address === false) {
            return;
        }

        $first = ip2long($this->block->firstUsable());
        $last = ip2long($this->block->lastUsable());

        if ($address < $first || $address > $last) {
            $fail("Adres {$this->block} bloğunun kullanılabilir aralığında değil.");
        }
    }
}
