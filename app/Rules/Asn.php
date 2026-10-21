<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Net\Asn as AsnValue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Girilen değerin geçerli bir AS numarası olmasını şart koşar.
 *
 * Ayrıştırma Support\Net\Asn ile yapılır; doğrulama, normalleştirme ve sorgu
 * uçları aynı tanımı kullanır.
 */
class Asn implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! AsnValue::isValid((string) $value)) {
            $fail('AS numarası "AS64496" ya da "64496" biçiminde olmalı ve 0-'.AsnValue::MAX.' aralığında kalmalı.');
        }
    }
}
