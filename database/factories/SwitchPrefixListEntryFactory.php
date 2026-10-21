<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IpFamily;
use App\Models\SwitchPrefixList;
use App\Models\SwitchPrefixListEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchPrefixListEntry>
 */
class SwitchPrefixListEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_prefix_list_id' => SwitchPrefixList::factory(),
            'address' => fake()->unique()->ipv4(),
            'prefix_length' => 32,
            'family' => IpFamily::Inet,
        ];
    }
}
