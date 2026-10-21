<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use Illuminate\Support\Str;

/**
 * Cihaz nesnelerinin yetenek adları.
 *
 * Port, VLAN, route, firewall gibi her nesne kendi yetki kaynağıdır ve
 * kayıtları switchlerdir. Her kaynak-aksiyon çifti için bir Gate üretilir:
 *
 *     switch_vlans + update  →  updateSwitchVlans
 *
 * Adlar tek yerden türetilir; controller, Blade ve policy aynı listeyi
 * okur. Elle yazılsalardı bir ekranda "updateVlans", diğerinde
 * "updateSwitchVlans" olur ve biri sessizce hiç kontrol edilmezdi.
 */
class DeviceObjectAbilities
{
    /** Cihaza YAZAN aksiyonlar; NETCONF kimliği ister. */
    public const WRITE_ACTIONS = ['create', 'update', 'delete'];

    public function __construct(private PermissionRegistry $registry) {}

    /**
     * Yetenek adı => [kaynak, aksiyon]
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function map(): array
    {
        $abilities = [];

        foreach ($this->resources() as $resource) {
            foreach ($this->registry->actionsFor($resource) as $action) {
                $abilities[$this->name($resource, $action)] = [$resource, $action];
            }
        }

        return $abilities;
    }

    /**
     * Cihaz nesnesi olan kaynaklar.
     *
     * @return list<string>
     */
    public function resources(): array
    {
        $resources = [];

        foreach ($this->registry->resources() as $key => $definition) {
            if ($definition['device_object'] ?? false) {
                $resources[] = $key;
            }
        }

        return $resources;
    }

    public function name(string $resource, string $action): string
    {
        return $action.Str::studly($resource);
    }
}
