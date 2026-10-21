<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Settings\SettingsAccess;

/**
 * Panel ayarları.
 *
 * Buradaki kontrol KABA olanıdır: "ayar ekranında işi var mı". Hangi bölümü
 * görebileceği ve değiştirebileceği bölüm bölüm sorulur (bkz. SettingsAccess);
 * bir bölüme yetkisi olan biri diğerine dokunamaz.
 *
 * Rol ve cihaz parolası ekranları buraya dahildir ve YALNIZCA yöneticidedir:
 * rol yetkisi verebilen biri kendine her şeyi verebilir.
 *
 * Gate::before yöneticiyi zaten geçirir; bu sınıf yönetici olmayanı durdurur.
 */
class SettingPolicy
{
    public function __construct(private UserPermissionResolver $resolver) {}

    public function viewAny(User $user): bool
    {
        return $this->resolver->allowsAny($user, SettingsAccess::RESOURCE, 'view');
    }

    /**
     * Kaba kontrol: en az bir bölümde yazma yetkisi var mı. Hangi bölüm
     * olduğu controller'da bölüm bölüm sorulur.
     */
    public function update(User $user, ?Setting $setting = null): bool
    {
        return $this->resolver->allowsAny($user, SettingsAccess::RESOURCE, 'update');
    }
}

/*
 * NOT: burada bir `administer()` metodu vardı ve docblock'u "rol, cihaz
 * parolası ve API jetonu ekranlarını korur" diyordu. HİÇBİR YERDEN
 * ÇAĞRILMIYORDU; o ekranlar kendi kapılarını taşıyor:
 *
 *   Roller          -> Gate 'manageRoles'         (yalnız yönetici)
 *   API jetonları   -> Gate 'manageApiTokens'     (yalnız yönetici)
 *   Cihaz parolaları-> Gate 'viewDevicePasswords' (en az bir switchte
 *                      'secret' ya da 'rotate' yetkisi — yönetici şartı YOK)
 *
 * Var olmayan bir korumayı anlatan yorum, olmayan bir korumadan daha
 * tehlikelidir: okuyan kişi kapının var olduğunu sanır.
 */
