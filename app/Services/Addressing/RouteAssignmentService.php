<?php

declare(strict_types=1);

namespace App\Services\Addressing;

use App\Enums\AddressConnectionType;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchRoute;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\RouteConfigurator;

/**
 * Adres bloğunun statik route atamasını CİHAZA yazar.
 *
 * Blok "Static Route" yöntemiyle bir omurga switchine bağlandığında panelde
 * bir kayıt oluşuyordu ama cihazda hiçbir şey değişmiyordu: blok panelde
 * "route edilmiş" görünürken trafiği kimse yönlendirmiyordu. Atamanın anlamı
 * ancak cihazda karşılığı olunca vardır.
 *
 * Prefix list atamasıyla aynı desen: önceki hâl silinir, yenisi yazılır ve
 * ne yapıldığı kullanıcıya söylenir. Sessizce commit atılmaz.
 */
class RouteAssignmentService
{
    public function __construct(private RouteConfigurator $routes) {}

    /**
     * Bloğun mevcut route atamasının parmak izi.
     *
     * Kaydetmeden ÖNCE alınır: değişip değişmediği ancak öncesiyle
     * karşılaştırılarak bilinir.
     *
     * @return array{switch_id: ?int, next_hop: ?string}
     */
    public function fingerprint(AddressBlock $block): array
    {
        return $block->connection_type === AddressConnectionType::NextHop
            ? ['switch_id' => $block->core_switch_id, 'next_hop' => $block->next_hop]
            : ['switch_id' => null, 'next_hop' => null];
    }

    /**
     * @param  array{switch_id: ?int, next_hop: ?string}  $previous  Değişiklikten ÖNCEKİ hâl
     * @return list<string> Kullanıcıya söylenecek işlemler
     *
     * @throws NetconfException
     */
    public function sync(AddressBlock $block, array $previous): array
    {
        $current = $this->fingerprint($block->refresh());

        if ($previous === $current) {
            return [];
        }

        $yapilanlar = [];

        /*
         * Önce eski satır çıkarılır. Ters sırada yapılsaydı, aynı hedefin
         * iki farklı sonraki atlamayla bulunduğu bir an olurdu ve cihaz
         * trafiği ikiye bölerdi.
         */
        if ($previous['switch_id'] !== null) {
            $yapilanlar = [...$yapilanlar, ...$this->remove($block, $previous['switch_id'])];
        }

        if ($current['switch_id'] !== null && $current['next_hop'] !== null) {
            $switch = NetworkSwitch::query()->find($current['switch_id']);

            if ($switch?->netconfCredential !== null) {
                $this->routes->create($switch, $block->network, $block->prefix_length, ['next_hops' => [$current['next_hop']]]);
                $yapilanlar[] = "{$block->cidr} → {$current['next_hop']} ({$switch->hostname})";
            }
        }

        return $yapilanlar;
    }

    /**
     * Blok silinirken cihazdaki route da çıkarılır; yoksa cihazda envanterde
     * karşılığı olmayan bir yol kalırdı.
     *
     * @return list<string>
     *
     * @throws NetconfException
     */
    public function detach(AddressBlock $block): array
    {
        $onceki = $this->fingerprint($block);

        return $onceki['switch_id'] === null ? [] : $this->remove($block, $onceki['switch_id']);
    }

    /**
     * @return list<string>
     *
     * @throws NetconfException
     */
    private function remove(AddressBlock $block, int $switchId): array
    {
        $switch = NetworkSwitch::query()->find($switchId);

        if ($switch?->netconfCredential === null) {
            return [];
        }

        $route = SwitchRoute::query()
            ->where('switch_id', $switchId)
            ->where('destination', $block->network)
            ->where('prefix_length', $block->prefix_length)
            ->first();

        /*
         * Yalnızca panelin yazabildiği (statik) satır silinir. Öğrenilmiş
         * bir yol aynı hedefi taşıyor olabilir; onu silmeye çalışmak cihazda
         * hata üretir ve zaten bizim yazdığımız satır değildir.
         */
        if ($route === null || ! $this->routes->isWritable($route)) {
            return [];
        }

        $this->routes->delete($switch, $route);

        return ["{$block->cidr} route'u {$switch->hostname} cihazından çıkarıldı"];
    }
}
