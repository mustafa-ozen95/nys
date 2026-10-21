<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;

/**
 * Ayar BÖLÜMÜ düzeyinde yetki.
 *
 * Ayarların tamamı tek bir anahtar değildir: ölçüm kaynağını değiştirmek ile
 * geri alınabilir commit süresini değiştirmek aynı ağırlıkta değil. Her bölüm
 * kendi kaydıdır (config'teki "permission_id") ve ayrı ayrı açılabilir.
 *
 * Varsayılan KAPALIDIR: hiçbir rol şablonunda "settings" yoktur, yalnızca
 * yönetici geçer. Bir bölümü başkasına açmak bilinçli bir karardır.
 *
 * Kimliği olmayan bölüm (config'e yeni eklenmiş, sayısı verilmemiş) YALNIZCA
 * yöneticiye açıktır: kimliksiz kayda yetki verilemez, sessizce herkese
 * açmak ise en kötüsü olurdu.
 */
class SettingsAccess
{
    public const RESOURCE = 'settings';

    public function __construct(private UserPermissionResolver $resolver) {}

    /**
     * @param  array{permission_id: ?int}  $group
     */
    public function canView(User $user, array $group): bool
    {
        return $this->allows($user, 'view', $group);
    }

    /**
     * @param  array{permission_id: ?int}  $group
     */
    public function canUpdate(User $user, array $group): bool
    {
        return $this->allows($user, 'update', $group);
    }

    /**
     * Kullanıcının görebildiği bölümler.
     *
     * @param  list<array{permission_id: ?int}>  $groups
     * @return list<array{permission_id: ?int}>
     */
    public function visible(User $user, array $groups): array
    {
        return array_values(array_filter(
            $groups,
            fn (array $group): bool => $this->canView($user, $group),
        ));
    }

    /**
     * @param  array{permission_id: ?int}  $group
     */
    private function allows(User $user, string $action, array $group): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $group['permission_id'] !== null
            && $this->resolver->allows($user, self::RESOURCE, $action, $group['permission_id']);
    }
}
