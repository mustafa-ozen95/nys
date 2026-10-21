<?php

declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\Validator;

/**
 * BGP hold-time'ın Junos'ta kabul ettiği aralık.
 *
 * Aralık süreklidir SANILIR ama değildir: 0 geçerlidir (süre işletilmez),
 * 1 ve 2 cihaz tarafından reddedilir, 3'ten sonrası serbesttir. Tek bir
 * "min" kuralı bunu ifade edemez; 0'ı yasaklarsa cihazda 0 yazan bir grup
 * panelden hiç düzenlenemez, 1'e izin verirse commit düşerdi.
 *
 * Grup ve komşu isteklerinin ikisi de aynı kuralı kullanır.
 */
final class HoldTime
{
    private const MINIMUM = 3;

    public static function validate(Validator $validator, mixed $value, string $field = 'hold_time'): void
    {
        if (! filled($value) || $validator->errors()->has($field)) {
            return;
        }

        $seconds = (int) $value;

        if ($seconds > 0 && $seconds < self::MINIMUM) {
            $validator->errors()->add(
                $field,
                'Hold-time 0 (süre işletilmez) ya da en az '.self::MINIMUM.' saniye olmalı.',
            );
        }
    }
}
