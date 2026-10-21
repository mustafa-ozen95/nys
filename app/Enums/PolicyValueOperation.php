<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * "then local-preference / metric / metric2 / preference" değer işlemi.
 *
 * Junos üçünü de kabul eder: değeri ata (`local-preference 200`), ekle
 * (`local-preference add 10`), çıkar (`local-preference subtract 10`).
 * Atama işlemi XML'de bir işaret elemanı YAZMAZ; add/subtract yazar.
 */
enum PolicyValueOperation: string
{
    case Set = 'set';
    case Add = 'add';
    case Subtract = 'subtract';

    public function label(): string
    {
        return match ($this) {
            self::Set => 'Ata',
            self::Add => 'Ekle',
            self::Subtract => 'Çıkar',
        };
    }

    public function badge(): BadgeVariant
    {
        return $this === self::Set ? BadgeVariant::Blue : BadgeVariant::Gray;
    }

    /**
     * XML'de yazılacak işaret elemanı; atamada eleman yoktur.
     */
    public function element(): ?string
    {
        return $this === self::Set ? null : $this->value;
    }
}
