<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * "from route-filter <prefix> <tür>" eşleşme türü.
 *
 * Junos'ta tür ZORUNLUDUR; türsüz route-filter reddedilir. Üçü değer de
 * alır: "upto /24", "through 10.1.0.0/16", "prefix-length-range /24-/32".
 *
 * Cihazda ölçüldü (test1, commit check): orlonger ve prefix-length-range
 * kabul edildi. Diğerleri aynı ailedendir ve Juniper belgesinde birlikte
 * tanımlıdır.
 */
enum RouteFilterMatchType: string
{
    case Exact = 'exact';
    case Longer = 'longer';
    case OrLonger = 'orlonger';
    case Upto = 'upto';
    case Through = 'through';
    case PrefixLengthRange = 'prefix-length-range';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Tam eşleşme (exact)',
            self::Longer => 'Yalnızca daha dar (longer)',
            self::OrLonger => 'Kendisi ve daha dar (orlonger)',
            self::Upto => 'Şu uzunluğa kadar (upto)',
            self::Through => 'Şu prefixe kadar (through)',
            self::PrefixLengthRange => 'Uzunluk aralığı (prefix-length-range)',
        };
    }

    /**
     * Rozet rengi: değer isteyen türler (aralık belirten) ayrı renkte durur.
     */
    public function badge(): BadgeVariant
    {
        return $this->needsValue() ? BadgeVariant::Purple : BadgeVariant::Blue;
    }

    /**
     * Tür bir DEĞER alıyor mu? exact/longer/orlonger boş elemandır
     * (<orlonger/>), diğer üçü metin taşır (<upto>/24</upto>).
     */
    public function needsValue(): bool
    {
        return match ($this) {
            self::Upto, self::Through, self::PrefixLengthRange => true,
            default => false,
        };
    }

    /**
     * Formda ne yazılacağını söyleyen örnek.
     */
    public function valueHint(): ?string
    {
        return match ($this) {
            self::Upto => '/24',
            self::Through => '10.1.0.0/16',
            self::PrefixLengthRange => '/24-/32',
            default => null,
        };
    }
}
