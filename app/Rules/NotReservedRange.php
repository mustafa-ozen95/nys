<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Net\Cidr;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Yönlendirilemeyen özel aralıklar bloğa dönüştürülemez.
 *
 * Bunlar yanlışlıkla girilirse switch tarafında anlamsız veya zararlı bir
 * yapılandırmaya yol açar; adresleme kaydı olarak da yanlıştır.
 */
class NotReservedRange implements ValidationRule
{
    /**
     * @var array<string, string> aralık => neden
     */
    private const RESERVED = [
        '0.0.0.0/8' => 'bu blok "bu ağ" anlamına gelir',
        '127.0.0.0/8' => 'loopback aralığı',
        '169.254.0.0/16' => 'link-local aralığı',
        '224.0.0.0/4' => 'multicast aralığı',
        '240.0.0.0/4' => 'ayrılmış aralık',

        /*
         * IPv6 tarafı da korunur; yoksa loopback ya da link-local bir blok
         * adresleme kaydına girebilirdi.
         */
        '::/128' => 'belirtilmemiş adres',
        '::1/128' => 'loopback adresi',
        'fe80::/10' => 'link-local aralığı',
        'ff00::/8' => 'multicast aralığı',
        '::ffff:0:0/96' => 'IPv4 eşlemeli aralık',
        '100::/64' => 'yönlendirilmeyen (discard) aralık',
    ];

    public function __construct(private int $prefixLength) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cidr = Cidr::tryParse(((string) $value).'/'.$this->prefixLength);

        if ($cidr === null) {
            return;
        }

        foreach (self::RESERVED as $range => $reason) {
            // overlaps() aile farkını zaten gözetiyor.
            if (Cidr::parse($range)->overlaps($cidr)) {
                $fail("{$cidr} kullanılamaz: {$range} {$reason}.");

                return;
            }
        }
    }
}
