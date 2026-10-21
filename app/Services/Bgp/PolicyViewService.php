<?php

declare(strict_types=1);

namespace App\Services\Bgp;

use App\Enums\CommunityOperation;
use App\Enums\PolicyAction;
use App\Enums\PolicyValueOperation;
use App\Enums\PrefixMatchType;
use App\Enums\RouteFilterMatchType;
use App\Models\NetworkSwitch;
use App\Models\SwitchPolicyStatement;
use BackedEnum;
use Illuminate\Support\Collection;

/**
 * Politika ekranının hazır verisi.
 *
 * Blade hesap yapmaz: seçenek listeleri, sıra seçenekleri ve atıf yapılabilir
 * tanımlar burada hazırlanır (bkz. .ai/rules/arayuz.md).
 */
class PolicyViewService
{
    /**
     * @return array<string, mixed>
     */
    public function screen(NetworkSwitch $switch): array
    {
        $policies = $switch->policyStatements()->with('terms')->orderBy('name')->get();

        /*
         * Terim yalnızca cihazda TANIMLI prefix list, community ve as-path'e
         * atıf yapabilir; tanımsız ada atıf commit'i düşürür.
         */
        $prefixLists = $switch->prefixLists()->orderBy('name')->pluck('name', 'name')->all();
        $communities = $switch->communities()->orderBy('name')->get();
        $asPaths = $switch->asPaths()->orderBy('name')->get();

        $communityNames = $communities->pluck('name', 'name')->all();
        $asPathNames = $asPaths->pluck('name', 'name')->all();

        return [
            'policies' => $policies,
            'positionOptions' => $this->positionOptions($policies),

            'communities' => $communities,
            'asPaths' => $asPaths,

            'prefixListOptions' => $prefixLists,
            'communityOptions' => ['' => 'Yok'] + $communityNames,
            'communityChoices' => $communityNames,
            'asPathChoices' => $asPathNames,

            'matchTypeOptions' => $this->options(PrefixMatchType::cases()),
            'routeFilterTypeOptions' => $this->options(RouteFilterMatchType::cases()),
            'actionOptions' => ['' => 'Yok'] + $this->options(PolicyAction::cases()),
            'valueOperationOptions' => $this->options(PolicyValueOperation::cases()),
            'communityOperationOptions' => $this->options(CommunityOperation::cases()),
        ];
    }

    /**
     * Terim sayısı + 1: sonuncu seçenek "en sona" demektir.
     *
     * @param  Collection<int, SwitchPolicyStatement>  $policies
     * @return array<string, array<int, string>>
     */
    private function positionOptions(Collection $policies): array
    {
        return $policies
            ->mapWithKeys(fn (SwitchPolicyStatement $policy): array => [
                $policy->uuid => collect(range(0, $policy->terms->count()))
                    ->mapWithKeys(fn (int $index): array => [$index => ($index + 1).'. sırada'])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @return array<string, string>
     */
    private function options(array $cases): array
    {
        return collect($cases)
            ->mapWithKeys(fn (BackedEnum $case): array => [$case->value => $case->label()])
            ->all();
    }
}
