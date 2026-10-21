<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Net\Cidr;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Girilen adres, seçilen maskenin gerçek ağ adresi olmalı.
 *
 * 198.18.21.7/24 sessizce 198.18.21.0/24'e çevrilirse kullanıcı yanlış bloğu
 * kaydettiğini fark etmez. Bu yüzden düzeltme yapılmaz, doğrusu söylenir.
 */
class AlignedNetwork implements ValidationRule
{
    public function __construct(private int $prefixLength) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cidr = Cidr::tryParse(((string) $value).'/'.$this->prefixLength);

        if ($cidr === null) {
            return;
        }

        if ($cidr->network !== (string) $value) {
            $fail("/{$this->prefixLength} için ağ adresi {$cidr->network} olmalı.");
        }
    }
}
