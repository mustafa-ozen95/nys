<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PolicyAction;
use App\Enums\PrefixMatchType;
use App\Models\SwitchPolicyStatement;
use App\Models\SwitchPolicyTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchPolicyTerm>
 */
class SwitchPolicyTermFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_policy_statement_id' => SwitchPolicyStatement::factory(),
            'name' => 'terim'.fake()->unique()->numberBetween(1, 9999),
            'position' => 0,
            'match_prefix_lists' => [],
            'match_type' => PrefixMatchType::OrLonger,
            'action' => PolicyAction::Accept,
            'community_delete' => null,
        ];
    }
}
