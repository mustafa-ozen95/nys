<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IpFamily;
use App\Models\AddressBlock;
use App\Support\Net\Cidr;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddressBlock>
 */
class AddressBlockFactory extends Factory
{
    public function definition(): array
    {
        $cidr = Cidr::make('10.'.fake()->unique()->numberBetween(0, 255).'.0.0', 16);

        return $this->fromCidr($cidr) + [
            'description' => fake()->words(3, true),
            'is_subnetted' => false,
        ];
    }

    /**
     * Ağ bilgisini tek kaynaktan üretir; sayısal aralık daima tutarlı kalır.
     *
     * @return array<string, mixed>
     */
    public function fromCidr(Cidr $cidr): array
    {
        return [
            'network' => $cidr->network,
            'prefix_length' => $cidr->prefixLength,
            'range_start' => $cidr->addressStart(),
            'range_end' => $cidr->addressEnd(),
            'family' => $cidr->isIpv6 ? IpFamily::Inet6 : IpFamily::Inet,
        ];
    }

    public function network(string $value): static
    {
        return $this->state(fn (): array => $this->fromCidr(Cidr::parse($value)));
    }
}
