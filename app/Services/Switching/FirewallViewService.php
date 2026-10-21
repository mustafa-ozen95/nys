<?php

declare(strict_types=1);

namespace App\Services\Switching;

use App\Enums\FirewallAction;
use App\Enums\FirewallFamily;
use App\Http\Requests\Firewall\FirewallTermRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchFirewallFilter;
use Illuminate\Support\Collection;

/**
 * Firewall ekranının hazır verisi.
 *
 * Blade filtreleme, sıralama ve hesap yapmaz; seçenek listeleri ve satır
 * özetleri burada kurulur.
 */
class FirewallViewService
{
    /**
     * @return array<string, mixed>
     */
    public function screen(NetworkSwitch $switch): array
    {
        $filters = $switch->firewallFilters()->with('terms')->orderBy('name')->get();
        $policers = $switch->firewallPolicers()->orderBy('name')->get();

        return [
            'filters' => $filters,
            'policers' => $policers,
            'positionOptions' => $this->positionOptions($filters),

            // Terim yalnızca cihazda TANIMLI prefix list'e ve policer'a atıf yapabilir.
            'prefixListOptions' => $switch->prefixLists()->orderBy('name')->pluck('name', 'name')->all(),
            'policerOptions' => ['' => 'Yok'] + $policers->pluck('name', 'name')->all(),

            'familyOptions' => $this->labels(FirewallFamily::cases()),
            'actionOptions' => ['' => 'Yok (Junos kabul eder)'] + $this->labels(FirewallAction::cases()),
            'lossPriorityOptions' => ['' => 'Yok'] + $this->plain(FirewallTermRequest::LOSS_PRIORITIES),
            'forwardingClassOptions' => ['' => 'Yok'] + $this->plain(FirewallTermRequest::FORWARDING_CLASSES),
            'dscpOptions' => ['' => 'Yok'] + $this->plain(FirewallTermRequest::DSCP_VALUES),

            'protocolOptions' => $this->plain(FirewallTermRequest::PROTOCOLS),
            'nextHeaderOptions' => $this->plain(FirewallTermRequest::NEXT_HEADERS),
            'icmpTypeOptions' => $this->plain(FirewallTermRequest::ICMP_TYPES),
            'ipOptionOptions' => $this->plain(FirewallTermRequest::IP_OPTIONS),
            'fragmentFlagOptions' => $this->plain(FirewallTermRequest::FRAGMENT_FLAGS),
            'precedenceOptions' => $this->plain(FirewallTermRequest::PRECEDENCES),
            'etherTypeOptions' => $this->plain(FirewallTermRequest::ETHER_TYPES),
            'dscpMatchOptions' => $this->plain(FirewallTermRequest::DSCP_VALUES),
        ];
    }

    /**
     * Her filtre için sıra seçenekleri. Terim sayısı + 1: sonuncu seçenek
     * "en sona" demektir.
     *
     * @param  Collection<int, SwitchFirewallFilter>  $filters
     * @return array<string, array<int, string>>
     */
    private function positionOptions(Collection $filters): array
    {
        return $filters
            ->mapWithKeys(fn (SwitchFirewallFilter $filter): array => [
                $filter->uuid => collect(range(0, $filter->terms->count()))
                    ->mapWithKeys(fn (int $index): array => [$index => ($index + 1).'. sırada'])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @param  list<FirewallFamily|FirewallAction>  $cases
     * @return array<string, string>
     */
    private function labels(array $cases): array
    {
        return collect($cases)
            ->mapWithKeys(fn ($case): array => [$case->value => $case->label()])
            ->all();
    }

    /**
     * Cihazın kendi sözcükleri: çevrilmez, olduğu gibi seçtirilir.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private function plain(array $values): array
    {
        return array_combine($values, $values);
    }
}
