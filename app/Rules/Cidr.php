<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Net\Cidr as CidrValue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Girilen her satırın geçerli bir CIDR bloğu olmasını şart koşar.
 *
 * Ayrıştırma Support\Net\Cidr ile yapılır; doğrulama ile ekranın geri kalanı
 * aynı tanımı kullanır.
 */
class Cidr implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (self::split((string) $value) as $line) {
            if (CidrValue::tryParse($line) === null) {
                $fail("Geçersiz prefix: {$line}");

                return;
            }
        }
    }

    /**
     * Serbest metni satır/virgül/boşluk ayrımıyla prefix listesine böler.
     *
     * @return array<int, string>
     */
    public static function split(string $value): array
    {
        return collect(preg_split('/[\s,;]+/', trim($value)) ?: [])
            ->filter(fn (string $line): bool => $line !== '')
            ->values()
            ->all();
    }
}
