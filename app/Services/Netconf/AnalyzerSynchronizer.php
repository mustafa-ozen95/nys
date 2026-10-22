<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use Illuminate\Support\Facades\DB;

/**
 * Port aynalama oturumlarını cihazdan envantere yazar.
 *
 * Budama KOŞULSUZDUR: cihazda hiç analyzer kalmaması gerçek bir durumdur
 * (son oturum silinmiştir). Envanterde hayalet bir kayıt bırakmak, hem
 * "bu portlar dinleniyor" diye YANLIŞ bilgi vermek hem de satırdan
 * "Düzenle" demenin aynalamayı cihazda yeniden yaratması demektir.
 */
class AnalyzerSynchronizer
{
    /**
     * Cihazdan gelen alanlar. "ad hariç her şey" demek yerine liste tutulur:
     * okuyucuya sonradan eklenen bir anahtar modelde yoksa sessizce
     * atılmamalı, listeye bilerek yazılmalı.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'ratio', 'loss_priority',
        'output_interface', 'output_vlan',
        'ingress_interfaces', 'ingress_vlans',
        'egress_interfaces', 'egress_vlans',
        'residual_xml',
    ];

    public function __construct(private AnalyzerReader $reader) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        $analyzers = $this->reader->analyzers($session, $switch);

        DB::transaction(function () use ($switch, $analyzers): void {
            $kept = [];

            foreach ($analyzers as $analyzer) {
                $kept[] = $switch->analyzers()->updateOrCreate(
                    ['name' => $analyzer['name']],
                    collect($analyzer)->only(self::FIELDS)->all(),
                )->id;
            }

            $switch->analyzers()->whereNotIn('id', $kept)->delete();
        });
    }
}
