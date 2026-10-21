<?php

declare(strict_types=1);

namespace App\Services\Addressing;

use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\User;

/**
 * Adres bloğu atamasının cihaza yazacağı değişiklikler için, o cihaz
 * nesnesinin KENDİ yetkisini arar.
 *
 * Adresleme yetkisi bir bloğu düzenlemeyi kapsar; statik route ya da arayüz
 * adresi yazmayı kapsamaz (switch_routes, switch_ip_addresses ayrı
 * kaynaklardır). Yalnızca adresleme yetkisi olan biri, atama yöntemi üzerinden
 * herhangi bir core/access switche route ve adres yazabiliyordu.
 *
 * Yetki yalnızca atama DEĞİŞİYORSA aranır: servisler de yalnızca değişimde
 * cihaza yazar. Açıklaması düzenlenen, ataması aynı kalan bir blok eskisi gibi
 * kaydedilebilmeli. NETCONF kimliği olmayan switche zaten hiçbir şey
 * yazılmaz; orada yetki aranmaz.
 */
class AssignmentRights
{
    public function __construct(
        private RouteAssignmentService $routes,
        private InterfaceAssignmentService $interfaces,
    ) {}

    /**
     * Eksik yetkiler: alan adı => ileti. Boşsa kayıt serbesttir.
     *
     * @param  AddressBlock|null  $before  Değişiklikten önceki blok (yeni blokta null)
     * @param  AddressBlock|null  $after  Değişiklikten sonraki hâl (silmede null)
     * @return array<string, string>
     */
    public function missing(User $user, ?AddressBlock $before, ?AddressBlock $after): array
    {
        $eksik = [];

        $eskiRoute = $before === null ? ['switch_id' => null, 'next_hop' => null] : $this->routes->fingerprint($before);
        $yeniRoute = $after === null ? ['switch_id' => null, 'next_hop' => null] : $this->routes->fingerprint($after);

        if ($eskiRoute !== $yeniRoute) {
            if ($eskiRoute['switch_id'] !== null && ! $this->may($user, 'deleteSwitchRoutes', $eskiRoute['switch_id'])) {
                $eksik['connection_type'] = 'Mevcut statik route’u kaldırmak için o switchte route silme yetkisi gerekir.';
            }

            if ($yeniRoute['switch_id'] !== null && ! $this->may($user, 'createSwitchRoutes', $yeniRoute['switch_id'])) {
                $eksik['connection_type'] = 'Statik route cihaza yazılır; bunun için o switchte route ekleme yetkisi gerekir.';
            }
        }

        $eskiArayuz = $before === null ? ['switch_id' => null, 'interface' => null, 'cidr' => null] : $this->interfaces->fingerprint($before);
        $yeniArayuz = $after === null ? ['switch_id' => null, 'interface' => null, 'cidr' => null] : $this->interfaces->fingerprint($after);

        if ($eskiArayuz !== $yeniArayuz) {
            if ($eskiArayuz['switch_id'] !== null && ! $this->may($user, 'deleteSwitchIpAddresses', $eskiArayuz['switch_id'])) {
                $eksik['connection_type'] = 'Mevcut geçit adresini kaldırmak için o switchte IP adresi silme yetkisi gerekir.';
            }

            if ($yeniArayuz['switch_id'] !== null && ! $this->may($user, 'createSwitchIpAddresses', $yeniArayuz['switch_id'])) {
                $eksik['connection_type'] = 'Geçit adresi cihaza yazılır; bunun için o switchte IP adresi ekleme yetkisi gerekir.';
            }
        }

        // Silmede prefix list satırı da cihazdan çıkar (eklemenin kuralı istekte).
        if ($after === null && $before?->switch_prefix_list_id !== null
            && $before->core_switch_id !== null
            && ! $this->may($user, 'configure', $before->core_switch_id)) {
            $eksik['prefix_list_id'] = 'Prefix list ataması omurga switchten çıkarılır; bunun için o switchte “Cihaza Yaz” yetkisi gerekir.';
        }

        return $eksik;
    }

    private function may(User $user, string $ability, int $switchId): bool
    {
        $switch = NetworkSwitch::query()->find($switchId);

        // Cihaza yazılamayan switchte servis hiçbir şey yapmaz.
        if ($switch === null || $switch->netconfCredential === null) {
            return true;
        }

        return $user->can('view', $switch) && $user->can($ability, $switch);
    }
}
