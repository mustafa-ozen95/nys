<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Models\SwitchPolicyStatement;
use Illuminate\Support\Facades\DB;

/**
 * Policy-statement'ları cihazdan envantere yazar.
 *
 * Kaynak cihazdır: panelde olup cihazda olmayan politika silinir. BGP grubunun
 * politika listesi bu tablodan doldurulduğu için güncel kalması şart — cihazda
 * silinmiş bir politikayı listede tutmak, kullanıcının reddedilecek bir commit
 * denemesine yol açardı.
 *
 * Politikanın KENDİ from/then'i ve panelin modellemediği ham satırlar da
 * yazılır: güncelleme politikayı silip yeniden yazdığı için, envanterde
 * olmayan satır cihazda da kaybolurdu.
 */
class PolicyStatementSynchronizer
{
    public function __construct(private PolicyStatementReader $reader) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        $statements = $this->reader->statements($session);
        $communities = $this->reader->communities($session);
        $asPaths = $this->reader->asPaths($session);

        DB::transaction(function () use ($switch, $statements, $communities, $asPaths): void {
            $this->storeCommunities($switch, $communities);
            $this->storeAsPaths($switch, $asPaths);

            $kept = [];

            foreach ($statements as $statement) {
                $model = $switch->policyStatements()->updateOrCreate(
                    ['name' => $statement['name']],
                    [
                        'default_match' => $statement['default_match'],
                        'default_then' => $statement['default_then'],
                        'residual_xml' => $statement['residual_xml'],
                        'is_manageable' => $statement['is_manageable'],
                        'is_inactive' => $statement['is_inactive'],
                    ],
                );

                $kept[] = $model->id;

                $this->storeTerms($model, $statement['terms']);
            }

            $switch->policyStatements()->whereNotIn('id', $kept)->delete();
        });
    }

    /**
     * Community'ler politikalardan ÖNCE yazılır: politika terimi bir
     * community'ye atıfta bulunuyorsa, o community'nin envanterde bulunması
     * gerekir.
     *
     * @param  list<array<string, mixed>>  $communities
     */
    private function storeCommunities(NetworkSwitch $switch, array $communities): void
    {
        $kept = [];

        foreach ($communities as $community) {
            $kept[] = $switch->communities()->updateOrCreate(
                ['name' => $community['name']],
                [
                    'members' => $community['members'],
                    'invert_match' => $community['invert_match'],
                    'residual_xml' => $community['residual_xml'],
                ],
            )->id;
        }

        $switch->communities()->whereNotIn('id', $kept)->delete();
    }

    /**
     * as-path tanımları da politikalardan öncedir; terim "from as-path <ad>"
     * ile bunlara atıfta bulunur.
     *
     * @param  list<array<string, mixed>>  $asPaths
     */
    private function storeAsPaths(NetworkSwitch $switch, array $asPaths): void
    {
        $kept = [];

        foreach ($asPaths as $asPath) {
            $kept[] = $switch->asPaths()->updateOrCreate(
                ['name' => $asPath['name']],
                ['path' => $asPath['path'], 'residual_xml' => $asPath['residual_xml']],
            )->id;
        }

        $switch->asPaths()->whereNotIn('id', $kept)->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $terms
     */
    private function storeTerms(SwitchPolicyStatement $statement, array $terms): void
    {
        $kept = [];

        foreach ($terms as $term) {
            $kept[] = $statement->terms()->updateOrCreate(
                ['name' => $term['name']],
                [
                    'position' => $term['position'],
                    'match_prefix_lists' => $term['match_prefix_lists'],
                    'match_type' => $term['match_type'],
                    'match_conditions' => $term['match_conditions'],
                    'action' => $term['action'],
                    'community_delete' => $term['community_delete'],
                    'then_actions' => $term['then_actions'],
                    'residual_xml' => $term['residual_xml'],
                    'is_inactive' => $term['is_inactive'],
                ],
            )->id;
        }

        $statement->terms()->whereNotIn('id', $kept)->delete();
    }
}
