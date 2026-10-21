<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BgpNlri;
use App\Enums\IpFamily;
use App\Models\BgpGroup;
use App\Models\BgpGroupFamily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BgpGroupFamily>
 */
class BgpGroupFamilyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bgp_group_id' => BgpGroup::factory(),
            'family' => IpFamily::Inet,
            'nlri' => BgpNlri::Any,
            'prefix_limit_maximum' => 200,
            'teardown' => false,
            'teardown_threshold' => null,
            'idle_timeout_forever' => false,
            'idle_timeout_minutes' => null,
        ];
    }

    /**
     * Omurgada yaygın kurgu: sınır aşılınca oturumu düşür ve süresiz kapalı kal.
     */
    public function teardownForever(): static
    {
        return $this->state(fn (): array => [
            'teardown' => true,
            'teardown_threshold' => 100,
            'idle_timeout_forever' => true,
        ]);
    }
}
