<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\AddressBlock;
use App\Support\Net\Cidr as CidrValue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Alt ağ, üst bloğun sınırları içinde olmalı.
 */
class WithinParentBlock implements ValidationRule
{
    public function __construct(private AddressBlock $parent, private int $prefixLength) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cidr = CidrValue::tryParse(((string) $value).'/'.$this->prefixLength);

        if ($cidr === null) {
            return;
        }

        if (! $this->parent->toCidr()->contains($cidr)) {
            $fail("{$cidr} bloğu {$this->parent->cidr} içinde değil.");
        }
    }
}
