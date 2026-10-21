<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

/**
 * Cihazın şablonu etkileyen özellikleri.
 *
 * Aynı config her cihaza yazılamaz: yönetim arayüzünün adı bile modele ve
 * kurulum biçimine göre değişir. EX4200 sanal şasi (virtual chassis) olarak
 * kurulduğunda yönetim arayüzü "vme", tek başına çalışırken "me0"dır. Şablon
 * bu bilgiyi uydurmaz, cihazdan okur.
 */
readonly class DeviceProfile
{
    public function __construct(
        public ?string $model,
        public ?string $version,
        public string $managementInterface,
        public ?string $hostname = null,
    ) {}
}
