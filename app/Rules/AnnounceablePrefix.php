<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * İnternete anons edilebilecek en dar IPv4 prefixi /24'tür.
 *
 * Daha dar bloklar (/25 ve sonrası) taşıyıcıların prefix filtrelerinde
 * düşürülür; anons edilmiş gibi kaydedilirse yanlış beklenti yaratır.
 */
class AnnounceablePrefix implements ValidationRule
{
    public const LONGEST_IPV4 = 24;

    /**
     * IPv6'da fiilen kabul edilen en dar anons /48'dir; daha darı büyük
     * taşıyıcıların süzgeçlerinden geçmez.
     */
    public const LONGEST_IPV6 = 48;

    public function __construct(private bool $announced, private bool $ipv6 = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->announced) {
            return;
        }

        $limit = $this->ipv6 ? self::LONGEST_IPV6 : self::LONGEST_IPV4;

        if ((int) $value > $limit) {
            $fail("Anons edilen prefix en fazla /{$limit} olabilir; daha dar bloklar internette anons edilmez.");
        }
    }
}
