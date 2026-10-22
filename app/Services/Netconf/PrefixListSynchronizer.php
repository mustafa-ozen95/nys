<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\IpFamily;
use App\Models\NetworkSwitch;
use App\Models\SwitchPrefixList;
use Illuminate\Support\Facades\DB;

/**
 * Prefix list'leri cihazdan envantere yazar.
 *
 * Girdisi olmayan liste de kaydedilir: boş bir prefix list gerçek bir
 * bulgudur. Uplink çıkış filtresi boş bir "koru" listesine dayanıyorsa bütün
 * trafiği düşürür; envanterde görünmezse fark edilmezdi.
 *
 * "Boş" iddiası ancak liste DİNAMİK değilse doğrudur: apply-path ile
 * doldurulan listenin girdileri yapılandırmada yazılı olmadığı için envantere
 * de girmez. O yüzden ifadenin kendisi saklanır; ekran boş listeyi tehlike
 * ilan etmeden önce ona bakar.
 *
 * Hem eşitleme işi hem de yazma sonrası geri okuma bunu kullanır.
 */
class PrefixListSynchronizer
{
    public function __construct(
        private PrefixListReader $reader,
        private EgressGuardReader $guards,
    ) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        $lists = $this->reader->prefixLists($session);

        /*
         * Hangi listenin çıkış bekçisi olduğu cihazdan ÇIKARILIR: firewall
         * filtresi ile arayüz bağlaması eşleştirilir. Elle işaretlenseydi
         * cihazda değiştiğinde panel yanlış bilgi gösterirdi.
         */
        $guards = $this->guards->guards($session);

        DB::transaction(function () use ($switch, $lists, $guards): void {
            $kept = [];

            foreach ($lists as $list) {
                $model = $switch->prefixLists()->updateOrCreate(
                    ['name' => $list['name']],
                    [
                        'egress_interfaces' => $guards[$list['name']] ?? null,
                        'apply_paths' => $list['apply_paths'],
                        'residual_xml' => $list['residual_xml'],

                        /*
                         * Liste HER ZAMAN yönetilebilir. apply-path
                         * modellendiği için panelin temsil edemediği bir
                         * liste yoktur; tanınmayan satırlar da ham hâlleriyle
                         * korunur. Salt okunur yapmak çözüm değildir:
                         * kullanıcı listeyi düzenleyemez, dinamik girdileri
                         * de göremezdi.
                         */
                        'is_manageable' => true,
                    ],
                );
                $kept[] = $model->id;

                $this->storeEntries($model, $list['entries']);
            }

            $switch->prefixLists()->whereNotIn('id', $kept)->delete();
        });
    }

    /**
     * @param  list<array{address: string, prefix_length: int, family: IpFamily}>  $entries
     */
    private function storeEntries(SwitchPrefixList $list, array $entries): void
    {
        $kept = [];

        foreach ($entries as $entry) {
            $kept[] = $list->entries()->updateOrCreate(
                ['address' => $entry['address'], 'prefix_length' => $entry['prefix_length']],
                ['family' => $entry['family']],
            )->id;
        }

        $list->entries()->whereNotIn('id', $kept)->delete();
    }
}
