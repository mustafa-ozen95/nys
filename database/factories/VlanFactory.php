<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkSwitch;
use App\Models\Vlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * L3 arayüzünün birim numarası bilerek etiketten FARKLIDIR.
 *
 * Junos'ta ikisinin eşit olması gerekmez (ör. V10 → vlan.9, V11 → vlan.10).
 * Fixture "vlan.<etiket>" verseydi, arayüz adını etiketten türeten bir hata
 * bütün testlerde doğru görünürdü — nitekim öyle olmuştu.
 *
 * @extends Factory<Vlan>
 */
class VlanFactory extends Factory
{
    public function definition(): array
    {
        $tag = fake()->unique()->numberBetween(2, 4094);

        return [
            'switch_id' => NetworkSwitch::factory(),
            'name' => 'V'.$tag,
            'vlan_tag' => $tag,
            'l3_interface' => 'vlan.'.($tag - 1),
        ];
    }
}
