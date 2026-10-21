<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ConfigurationPush;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;

/**
 * Config yükleme ekranını kimin göreceği.
 *
 * Ekran bir kaynağın listesi değil bir EYLEMDİR: herhangi bir cihazda config
 * yükleme yetkisi olan görür. Yönetici, envanterde olmayan cihazlara da
 * yazabildiği için her hâlükârda görür.
 *
 * Kontrol yetki satırları üzerinden yapılır, cihazlar üzerinden DEĞİL: bu
 * policy üst menüde her sayfada çalışır ve switch listesini gezerse her
 * sayfa yüklemesine bir sorgu ile cihaz başına bir Gate çağrısı ekler.
 * Hangi cihaza yazılabileceği zaten ekranın kendi listesinde süzülüyor.
 *
 * Geçmiş satırı düzenlenmez ve silinmez; olan biten olmuş bitmiştir.
 */
class ConfigurationPushPolicy
{
    public function __construct(private UserPermissionResolver $resolver) {}

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $this->resolver->allowsAny($user, 'switches', 'provision');
    }

    /**
     * Tekil kayıt: ekranı görebilmek yetmez, O CİHAZA yazma yetkisi aranır.
     * Envanter dışı cihazın kaydını yalnızca yönetici görür.
     *
     * AYNI KURALIN İKİNCİ YOLU: liste sorgusu bunu SQL olarak söyler
     * (bkz. ConfigurationPush::scopeVisibleTo). İkisi ayrışamaz; ayrıştığında
     * listede görünen bir kaydın detayı açılmaz ya da tersi olur. Kilit:
     * tests/Feature/Provisioning/ConfigurationPushTest — "policy ve sorgu
     * aynı şeyi söyler".
     */
    public function view(User $user, ConfigurationPush $push): bool
    {
        if ($push->switch === null) {
            return $user->isAdmin();
        }

        return $user->can('provision', $push->switch);
    }
}
