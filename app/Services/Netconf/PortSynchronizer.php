<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\PortType;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\Vlan;
use Illuminate\Support\Facades\DB;

/**
 * Portları ve VLAN'ları cihazdan envantere yazar.
 *
 * Kaynak ayrımı: yapılandırma ve durum NETCONF'tan, ölçüm (anlık hız, duplex)
 * Zabbix'ten gelir. Aynı alanı iki kaynağın yazması karışıklık üretir.
 *
 * FİZİKSEL port silinmez: cihazdan kaybolmaz ve Zabbix de aynı tabloya
 * yazabilir; silme yapılsaydı iki iş birbirinin kaydını sürekli silip yazardı.
 *
 * TOPLAMA arayüzü (ae) ise silinir: donanım değildir, chassis device-count
 * kadarını yaratır ve sayı düşürüldüğünde üsttekiler gerçekten yok olur.
 * Envanterde tutulsalardı panel var olmayan bir arayüzü yönetilebilir
 * gösterirdi.
 *
 * VLAN da silinebilir; cihazdan kaldırılan VLAN envanterden de düşer.
 *
 * Hem eşitleme işi hem de port yazma sonrası geri okuma bunu kullanır.
 */
class PortSynchronizer
{
    public function __construct(private PortReader $reader) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        ['ports' => $ports, 'vlans' => $vlans, 'device_count' => $deviceCount] = $this->reader->ports($session);

        // Cihaz hiç port döndürmediyse beklenmedik bir yanıt gelmiştir;
        // mevcut envanter bozulmaz.
        if ($ports === []) {
            return;
        }

        DB::transaction(function () use ($switch, $ports, $vlans, $deviceCount): void {
            $switch->update(['aggregated_device_count' => $deviceCount]);

            $models = $this->storeVlans($switch, $vlans);

            foreach ($ports as $port) {
                $this->storePort($switch, $port, $models);
            }

            /*
             * Üyelikler İKİNCİ geçişte kurulur: bir port kendisinden sonra
             * okunan bir ae'ye üye olabilir ve tek geçişte hedef henüz
             * kaydedilmemiş olurdu.
             */
            $this->storeBundles($switch, $ports);

            $this->pruneAggregates($switch, $ports);
        });
    }

    /**
     * @param  array<string, array{tag: ?int, l3_interface: ?string, description: ?string}>  $vlans
     * @return array<string, Vlan> VLAN adına göre
     */
    private function storeVlans(NetworkSwitch $switch, array $vlans): array
    {
        $models = [];

        /*
         * Kimlik ETİKET DEĞİL ADDIR — cihazda da öyle: port üyelikleri ve
         * l3-interface bağları adla kurulur, etiket sonradan değişebilir ve
         * hiç olmayabilir. Etiketle eşleştirmek etiketsiz VLAN'ları
         * birbirine karıştırırdı.
         */
        /*
         * Etiket TAŞINIYORSA önce serbest bırakılır.
         *
         * Cihazda iki VLAN aynı etiketi taşıyamaz ve envanterde de tekillik
         * kısıtı var. İki VLAN etiket takas ederse (V1 10→20, V2 20→10)
         * döngünün ilk adımı hâlâ eski sahibinde duran etikete çarpar ve
         * bütün eşitleme geri sarılırdı. Etiketin boş kalabilmesi bu adımı
         * mümkün kılıyor.
         */
        $gelen = array_filter(array_column($vlans, 'tag'), static fn (?int $tag): bool => $tag !== null);

        if ($gelen !== []) {
            $switch->vlans()
                ->whereIn('vlan_tag', $gelen)
                ->whereNotIn('name', array_keys($vlans))
                ->update(['vlan_tag' => null]);

            foreach ($vlans as $name => $vlan) {
                if ($vlan['tag'] !== null) {
                    $switch->vlans()
                        ->where('vlan_tag', $vlan['tag'])
                        ->where('name', '!=', $name)
                        ->update(['vlan_tag' => null]);
                }
            }
        }

        foreach ($vlans as $name => $vlan) {
            $models[$name] = $switch->vlans()->updateOrCreate(
                ['name' => $name],
                [
                    'vlan_tag' => $vlan['tag'],
                    'l3_interface' => $vlan['l3_interface'],
                    'description' => $vlan['description'] ?? null,
                ],
            );
        }

        /*
         * Budama KOŞULSUZDUR. "Liste boşsa dokunma" korumasının amacı bozuk
         * bir yanıtta envanteri silmemekti, ama o koruma zaten yukarıda:
         * cihaz hiç port döndürmediyse sync() işi buraya kadar getirmiyor.
         * Burada boş liste "cihazda VLAN kalmadı" demektir ve gerçek bir
         * durumdur — son VLAN silindiğinde. Koşul kaldığı sürece o kayıt
         * envanterde hayalet olarak kalıyor, satırdaki "Düzenle" onu cihazda
         * yeniden yaratıyordu.
         */
        $switch->vlans()->whereNotIn('name', array_keys($vlans))->delete();

        return $models;
    }

    /**
     * @param  array<string, mixed>  $port
     * @param  array<string, Vlan>  $vlans
     */
    private function storePort(NetworkSwitch $switch, array $port, array $vlans): void
    {
        $model = $switch->ports()->updateOrCreate(
            ['name' => $port['name']],
            [
                'interface_kind' => $port['interface_kind'],
                'if_index' => $port['if_index'],
                'description' => $port['description'],
                'mtu' => $port['mtu'] ?? null,
                'admin_status' => $port['admin_status'],
                'oper_status' => $port['oper_status'],
                'speed_config' => $port['speed_config'],
                'duplex_config' => $port['duplex_config'] ?? null,
                'is_autonegotiation_enabled' => $port['is_autonegotiation_enabled'],
                'is_poe_enabled' => $port['is_poe_enabled'],
                'port_mode' => $port['port_mode'],
                'lacp_mode' => $port['lacp_mode'],
                'lacp_periodic' => $port['lacp_periodic'],
                'minimum_links' => $port['minimum_links'],
                'link_speed' => $port['link_speed'],
            ],
        );

        $this->storeMemberships($model, $port, $vlans);
    }

    /**
     * Fiziksel portların ae üyeliği.
     *
     * @param  list<array<string, mixed>>  $ports
     */
    private function storeBundles(NetworkSwitch $switch, array $ports): void
    {
        $kimlikler = $switch->ports()->pluck('id', 'name');

        foreach ($ports as $port) {
            if ($port['interface_kind'] !== PortType::Physical) {
                continue;
            }

            $switch->ports()->where('name', $port['name'])->update([
                'aggregate_port_id' => $port['bundle'] === null
                    ? null
                    : $kimlikler[$port['bundle']] ?? null,
            ]);
        }
    }

    /**
     * Cihazda artık bulunmayan toplama arayüzlerini düşürür.
     *
     * device-count 4'ten 2'ye indirildiğinde ae2 ve ae3 cihazda gerçekten yok
     * oluyor; envanterde kalsalardı panel olmayan bir arayüzü yönetilebilir
     * gösterirdi.
     *
     * @param  list<array<string, mixed>>  $ports
     */
    private function pruneAggregates(NetworkSwitch $switch, array $ports): void
    {
        $mevcut = array_column(
            array_filter($ports, static fn (array $port): bool => $port['interface_kind'] === PortType::Aggregated),
            'name',
        );

        $switch->ports()->aggregated()->whereNotIn('name', $mevcut)->delete();
    }

    /**
     * @param  array{vlans: list<string>, untagged: ?string}  $port
     * @param  array<string, Vlan>  $vlans
     */
    private function storeMemberships(SwitchPort $model, array $port, array $vlans): void
    {
        $pivot = [];

        foreach ($port['vlans'] as $name) {
            if (isset($vlans[$name])) {
                $pivot[$vlans[$name]->id] = ['is_untagged' => false];
            }
        }

        if ($port['untagged'] !== null && isset($vlans[$port['untagged']])) {
            $pivot[$vlans[$port['untagged']]->id] = ['is_untagged' => true];
        }

        $model->vlans()->sync($pivot);
    }
}
