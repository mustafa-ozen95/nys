<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FirewallFamily;
use App\Models\NetworkSwitch;
use App\Models\SwitchFirewallFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchFirewallFilter>
 */
class SwitchFirewallFilterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'family' => FirewallFamily::Inet,
            'name' => 'filtre'.fake()->unique()->numberBetween(1, 9999),
            'interface_specific' => false,
            'is_manageable' => true,
            'bindings' => null,
            'residual_xml' => null,
        ];
    }

    /**
     * Panelin göstermediği ama KORUNAN satırı olan filtre.
     *
     * Böyle bir filtre artık salt okunur değildir: satır ham hâliyle saklanır
     * ve geri yazılırken aynen basılır.
     */
    public function withPreservedLines(string $xml = '<uydurma-satir>x</uydurma-satir>'): static
    {
        return $this->state(fn (): array => ['residual_xml' => [$xml]]);
    }

    /**
     * Bir arayüze bağlı filtre: silinmesi ya da değiştirilmesi gerçek trafiği
     * etkiler.
     */
    public function bound(string $interface = 'ge-0/0/23.0', string $direction = 'output'): static
    {
        return $this->state(fn (): array => [
            'bindings' => [['interface' => $interface, 'direction' => $direction]],
        ]);
    }
}
