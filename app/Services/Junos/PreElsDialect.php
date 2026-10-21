<?php

declare(strict_types=1);

namespace App\Services\Junos;

/**
 * ELS ÖNCESİ Junos (EX2200 / EX3200 / EX4200 / EX4500, 12.3R12.4).
 *
 * Şekillerin tümü test1 (EX3200), test2 (EX4200) ve test3 (EX2200-48T-4G)
 * üzerinde commit check ile ölçüldü.
 *
 * Varsayılanlar zaten bu aileye göre yazıldığı için burada ezilen bir şey
 * yoktur; sınıf, ailenin AÇIKÇA adlandırılması ve yeni bir lehçe eklenirken
 * neyin varsayılan olduğunun görünmesi için durur.
 */
class PreElsDialect extends JunosDialect
{
    public function key(): string
    {
        return 'pre_els';
    }

    public function matches(?string $model, ?string $version): bool
    {
        // 12.x ve öncesi EX kasaları ELS öncesidir.
        return $model !== null
            && preg_match('/^EX(22|32|42|45)/i', $model) === 1;
    }
}
