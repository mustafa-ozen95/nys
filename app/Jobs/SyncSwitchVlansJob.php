<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PortMode;
use App\Models\NetworkSwitch;
use App\Models\Vlan;
use App\Services\Snmp\SnmpException;
use App\Services\Snmp\VlanReader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Switchin VLAN'larını ve port üyeliklerini cihazdan okur.
 *
 * SNMP ile YALNIZCA OKUMA yapılır; cihaza hiçbir şey yazılmaz.
 * Cihaz yanıt vermezse mevcut kayıtlar silinmez, iş sessizce biter:
 * geçici bir erişim sorunu yüzünden envanter boşalmamalı.
 */
class SyncSwitchVlansJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * Kilidin en fazla ne kadar tutulacağı.
     *
     * Bu OLMADAN kilit iş bitene kadar durur; iş bir şekilde kaybolursa
     * (yanlış kuyruk, düşen işçi) kilit sonsuza kadar kalır ve sonraki her
     * dispatch SESSİZCE atılır. Eşitleme yarım saatte bir koştuğu için
     * kilit ondan önce düşmeli.
     */
    public int $uniqueFor = 1500;

    public function __construct(public int $switchId)
    {
        $this->onQueue(config('snmp.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->switchId;
    }

    public function handle(VlanReader $reader): void
    {
        $switch = NetworkSwitch::query()->with('ports')->find($this->switchId);

        if ($switch === null || $switch->snmpCredential === null) {
            return;
        }

        /*
         * NETCONF varsa bu iş çalışmaz: VLAN'lar ve port üyelikleri
         * yapılandırmadan doğrudan okunuyor, üstelik port yazma yolu da oradan
         * okuduğu için tek sahip olmalı. Bu yol yalnızca NETCONF kimliği
         * tanımlı olmayan cihazlar için kalır.
         */
        if ($switch->netconfCredential !== null) {
            return;
        }

        try {
            $vlans = $reader->vlans($switch);
            $memberships = $reader->memberships($switch);
            $native = $reader->nativeVlans($switch);
        } catch (SnmpException $exception) {
            Log::warning("VLAN okunamadı: {$switch->hostname}", ['hata' => $exception->getMessage()]);

            return;
        }

        if ($vlans === []) {
            return;
        }

        DB::transaction(function () use ($switch, $vlans, $memberships, $native): void {
            $models = $this->storeVlans($switch, $vlans);
            $this->storeMemberships($switch, $vlans, $models, $memberships, $native);
        });
    }

    /**
     * @param  array<int, array{tag: int, name: string, l3_interface: ?string}>  $vlans
     * @return array<int, Vlan> Juniper iç indeksine göre
     */
    private function storeVlans(NetworkSwitch $switch, array $vlans): array
    {
        $models = [];

        foreach ($vlans as $index => $vlan) {
            $models[$index] = $switch->vlans()->updateOrCreate(
                ['name' => $vlan['name']],
                ['vlan_tag' => $vlan['tag'], 'l3_interface' => $vlan['l3_interface']],
            );
        }

        /*
         * Cihazda kalmayan VLAN'lar envanterden de düşer — ama yalnızca
         * SNMP'nin GÖREBİLDİKLERİ arasından. SNMP tablosu VLAN'ları etiketle
         * listeler; etiketsiz VLAN oraya hiç girmez. Ada göre budansaydı
         * NETCONF'un bulduğu etiketsiz VLAN'ları bu iş her turda silerdi.
         */
        $switch->vlans()
            ->whereNotNull('vlan_tag')
            ->whereNotIn('vlan_tag', array_column($vlans, 'tag'))
            ->delete();

        return $models;
    }

    /**
     * @param  array<int, array{tag: int, name: string, l3_interface: ?string}>  $vlans
     * @param  array<int, Vlan>  $models
     * @param  array<int, array<int, bool>>  $memberships
     * @param  array<int, int>  $native
     */
    private function storeMemberships(
        NetworkSwitch $switch,
        array $vlans,
        array $models,
        array $memberships,
        array $native,
    ): void {
        // SNMP tablosu yalnızca etiketli VLAN'ları listeler; etiketsizler buraya girmez.
        $tagToModel = collect($models)->filter(fn (Vlan $vlan): bool => $vlan->vlan_tag !== null)
            ->keyBy(fn (Vlan $vlan): int => $vlan->vlan_tag);

        foreach ($switch->ports as $port) {
            $portMemberships = $memberships[$port->if_index] ?? [];
            $pivot = [];

            foreach ($portMemberships as $vlanIndex => $untagged) {
                $model = $models[$vlanIndex] ?? null;

                if ($model !== null) {
                    $pivot[$model->id] = ['is_untagged' => $untagged];
                }
            }

            // Üyelik tablosunda görünmeyen port, PVID'sinden bilinebilir.
            if ($pivot === [] && isset($native[$port->if_index])) {
                $model = $tagToModel->get($native[$port->if_index]);

                if ($model !== null) {
                    $pivot[$model->id] = ['is_untagged' => true];
                }
            }

            $port->vlans()->sync($pivot);
            $port->update(['port_mode' => $this->mode($pivot)]);
        }
    }

    /**
     * @param  array<int, array<string, bool>>  $pivot
     */
    private function mode(array $pivot): PortMode
    {
        if ($pivot === []) {
            return PortMode::Unconfigured;
        }

        $tagged = collect($pivot)->contains(fn (array $row): bool => ! $row['is_untagged']);

        return $tagged || count($pivot) > 1 ? PortMode::Trunk : PortMode::Access;
    }
}
