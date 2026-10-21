<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Kayıtlara dışarıya açılan bir kimlik verir.
 *
 * Adres çubuğunda ve formlarda sıralı birincil anahtar görünmez: bir kaydın
 * kimliğini bilmek diğerlerininkini tahmin etmeyi mümkün kılmamalı. İlişkiler
 * ve yabancı anahtarlar içeride tamsayı kalmaya devam eder.
 */
trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
