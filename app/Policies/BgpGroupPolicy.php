<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Gruplar "bgp" kaynağı altında yetkilendirilir. Cihaza YAZMA yetkisi burada
 * değil, switchin "configure" yetkisindedir: yazan uçlar
 * can:configure,switch ile korunur.
 */
class BgpGroupPolicy extends BaseResourcePolicy
{
    protected function resource(): string
    {
        return 'bgp';
    }
}
