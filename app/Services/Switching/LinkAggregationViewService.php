<?php

declare(strict_types=1);

namespace App\Services\Switching;

use App\Enums\LacpMode;
use App\Enums\LacpPeriodic;
use App\Enums\PortMode;
use App\Http\Requests\Aggregation\LinkAggregationRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\Vlan;

/**
 * LAG ekranının hazır verisi.
 */
class LinkAggregationViewService
{
    /**
     * @return array<string, mixed>
     */
    public function screen(NetworkSwitch $switch): array
    {
        $vlans = $switch->vlans()->orderBy('vlan_tag')->get();

        $aggregates = $switch->ports()
            ->aggregated()
            ->with(['members' => fn ($query) => $query->orderBy('if_index'), 'vlans'])
            ->orderBy('name')
            ->get();

        return [
            'aggregates' => $aggregates,
            'deviceCount' => $switch->aggregated_device_count,

            /*
             * Üye olabilecek portlar: bu switchin fiziksel portları. Başka bir
             * ae'ye üye olanlar listede ADI ile birlikte görünür ki kullanıcı
             * neden seçemediğini anlasın; sunucu da ayrıca reddeder.
             */
            'memberOptions' => $switch->ports()
                ->physical()
                ->with('aggregate')
                ->orderBy('if_index')
                ->get()
                ->mapWithKeys(fn (SwitchPort $port): array => [
                    $port->uuid => $port->name.($port->aggregate === null ? '' : " · {$port->aggregate->name} üyesi"),
                ])
                ->all(),

            'vlanOptions' => $vlans->mapWithKeys(
                fn (Vlan $vlan): array => [$vlan->uuid => $vlan->label]
            )->all(),
            'untaggedOptions' => ['' => 'Yok'] + $vlans->mapWithKeys(
                fn (Vlan $vlan): array => [$vlan->uuid => $vlan->label]
            )->all(),

            'modeOptions' => [
                PortMode::Access->value => PortMode::Access->label(),
                PortMode::Trunk->value => PortMode::Trunk->label(),
            ],
            'lacpOptions' => ['' => 'Yok (statik)'] + collect(LacpMode::cases())
                ->mapWithKeys(fn (LacpMode $case): array => [$case->value => $case->label()])
                ->all(),
            'periodicOptions' => ['' => 'Varsayılan'] + collect(LacpPeriodic::cases())
                ->mapWithKeys(fn (LacpPeriodic $case): array => [$case->value => $case->label()])
                ->all(),
            'linkSpeedOptions' => ['' => 'Belirtilmemiş'] + collect(LinkAggregationRequest::LINK_SPEEDS)
                ->mapWithKeys(fn (string $speed): array => [$speed => strtoupper($speed)])
                ->all(),
            'maxMembers' => LinkAggregationRequest::MAX_MEMBERS,
        ];
    }
}
