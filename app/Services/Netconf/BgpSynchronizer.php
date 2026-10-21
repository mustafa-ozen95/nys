<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\BgpState;
use App\Models\BgpGroup;
use App\Models\NetworkSwitch;
use Illuminate\Support\Facades\DB;

/**
 * BGP yapılandırmasını ve oturum durumunu cihazdan envantere yazar.
 *
 * Kaynak daima cihazdır: panelde olup cihazda olmayan grup ya da komşu
 * silinir. Panel niyeti değil, cihazın gerçeğini gösterir.
 *
 * Durum ile yapılandırma AYNI eşitlemede yazılır ama farklı RPC'lerden gelir.
 * Durum okunamazsa (BGP hiç yapılandırılmamışsa cihaz boş yanıt verir)
 * yapılandırma yine de yazılır; komşular "bilinmiyor" durumunda kalır.
 *
 * Hem eşitleme işi hem de yazma sonrası geri okuma bunu kullanır.
 */
class BgpSynchronizer
{
    public function __construct(private BgpReader $reader) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        $asn = $this->reader->autonomousSystem($session);
        $groups = $this->reader->groups($session);
        $states = $this->neighborStates($session, $groups);

        DB::transaction(function () use ($switch, $asn, $groups, $states): void {
            $switch->update(['autonomous_system' => $asn]);

            $tutulan = [];

            foreach ($groups as $group) {
                $model = $switch->bgpGroups()->updateOrCreate(
                    ['name' => $group['name']],
                    [
                        'type' => $group['type'],
                        'peer_asn' => $group['peer_asn'],
                        'local_asn' => $group['local_asn'],
                        'local_address' => $group['local_address'],
                        'authentication_key' => $group['authentication_key'],
                        'hold_time' => $group['hold_time'],
                        'cluster' => $group['cluster'],
                        'graceful_restart' => $group['graceful_restart'],
                        'remove_private' => $group['remove_private'],
                        'as_override' => $group['as_override'],
                        'advertise_inactive' => $group['advertise_inactive'],
                        'multipath' => $group['multipath'],
                        'damping' => $group['damping'],
                        'out_delay' => $group['out_delay'],
                        'bfd_minimum_interval' => $group['bfd_minimum_interval'],
                        'allow_prefixes' => $group['allow_prefixes'],
                        'multihop' => $group['multihop'],
                        'multihop_ttl' => $group['multihop_ttl'],
                        'log_updown' => $group['log_updown'],
                        'passive' => $group['passive'],
                        'import_policies' => $group['import_policies'],
                        'export_policies' => $group['export_policies'],
                        'description' => $group['description'],
                        'is_inactive' => $group['is_inactive'],
                    ],
                );

                $tutulan[] = $model->id;

                $this->storeFamilies($model, $group['families']);
                $this->storeNeighbors($switch, $model, $group['neighbors'], $states);
            }

            $switch->bgpGroups()->whereNotIn('id', $tutulan)->delete();
        });
    }

    /**
     * Cihazda hiç grup yoksa durum RPC'si de çalıştırılmaz: BGP kapalıyken
     * Junos bu RPC'ye hata döner ve bütün eşitlemeyi düşürürdü.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     *
     * @throws NetconfException
     */
    private function neighborStates(NetconfSession $session, array $groups): array
    {
        return $groups === [] ? [] : $this->reader->neighborStates($session);
    }

    /**
     * @param  list<array<string, mixed>>  $families
     */
    private function storeFamilies(BgpGroup $group, array $families): void
    {
        $tutulan = [];

        foreach ($families as $family) {
            $tutulan[] = $group->families()->updateOrCreate(
                ['family' => $family['family']],
                [
                    'nlri' => $family['nlri'],
                    'prefix_limit_maximum' => $family['prefix_limit_maximum'],
                    'teardown' => $family['teardown'],
                    'teardown_threshold' => $family['teardown_threshold'],
                    'idle_timeout_forever' => $family['idle_timeout_forever'],
                    'idle_timeout_minutes' => $family['idle_timeout_minutes'],
                ],
            )->id;
        }

        $group->families()->whereNotIn('id', $tutulan)->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $neighbors
     * @param  array<string, array<string, mixed>>  $states
     */
    private function storeNeighbors(NetworkSwitch $switch, BgpGroup $group, array $neighbors, array $states): void
    {
        $tutulan = [];

        foreach ($neighbors as $neighbor) {
            /*
             * Komşu adresi switch genelinde benzersizdir: bir komşu gruptan
             * gruba taşınmış olabilir ve o zaman kayıt TAŞINIR, ikizlenmez.
             * Arama bu yüzden grup üzerinden değil switch üzerinden yapılır.
             */
            $model = $switch->bgpNeighbors()->firstOrNew(['address' => $neighbor['address']]);

            $model->fill([
                'bgp_group_id' => $group->id,
                'description' => $neighbor['description'],
                'peer_asn' => $neighbor['peer_asn'],
                'local_address' => $neighbor['local_address'],
                'authentication_key' => $neighbor['authentication_key'],
                'hold_time' => $neighbor['hold_time'],
                'passive' => $neighbor['passive'],
                'multihop' => $neighbor['multihop'],
                'multihop_ttl' => $neighbor['multihop_ttl'],
                'import_policies' => $neighbor['import_policies'],
                'export_policies' => $neighbor['export_policies'],
                'is_inactive' => $neighbor['is_inactive'],
            ]);

            /*
             * Durum alanları $fillable dışındadır ve yalnızca burada yazılır.
             * Cihaz o komşu için durum vermediyse (oturum hiç kurulmamışsa)
             * eskisi korunmaz, "bilinmiyor" yazılır: eski bir durumu güncelmiş
             * gibi göstermek yanlış bilgi olurdu.
             */
            $state = $states[$neighbor['address']] ?? null;

            $model->state = $state['state'] ?? BgpState::Unknown;
            $model->active_prefixes = $state['active_prefixes'] ?? null;
            $model->received_prefixes = $state['received_prefixes'] ?? null;
            $model->accepted_prefixes = $state['accepted_prefixes'] ?? null;
            $model->flap_count = $state['flap_count'] ?? null;
            $model->state_changed_seconds = $state['state_changed_seconds'] ?? null;

            $model->save();

            $tutulan[] = $model->id;
        }

        $group->neighbors()->whereNotIn('id', $tutulan)->delete();
    }
}
