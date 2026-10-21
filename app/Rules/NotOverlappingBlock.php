<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\IpFamily;
use App\Models\AddressBlock;
use App\Support\Net\Cidr as CidrValue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Aynı seviyede çakışan blok olamaz.
 *
 * Kontrol PAKETLENMİŞ BAYT aralığıyla veritabanında yapılır ve AİLEYİ de
 * süzer: bir IPv4 bloğu bir IPv6 bloğuyla çakışamaz, baytlarını
 * karşılaştırmak anlamsız olurdu.
 */
class NotOverlappingBlock implements ValidationRule
{
    public function __construct(private int $prefixLength, private ?AddressBlock $parent = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cidr = CidrValue::tryParse(((string) $value).'/'.$this->prefixLength);

        if ($cidr === null) {
            return;
        }

        $cakisan = AddressBlock::query()
            ->when(
                $this->parent === null,
                fn ($query) => $query->roots(),
                fn ($query) => $query->where('parent_id', $this->parent->id),
            )
            ->overlapping($cidr->addressStart(), $cidr->addressEnd(), $cidr->isIpv6 ? IpFamily::Inet6 : IpFamily::Inet)
            ->first();

        if ($cakisan !== null) {
            $fail("{$cidr} bloğu mevcut {$cakisan->cidr} bloğuyla çakışıyor.");
        }
    }
}
