<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Role;

/**
 * Rolün yetkileri — CANLI okunur.
 *
 * NEDEN CANLI: rol bir "başlangıç kümesi" olup yetkiler hesap açılırken
 * kullanıcıya KOPYALANSAYDI kopya eskirdi: rolü düzenleyen kişi ekranda yeni
 * yetkiyi görür, kullanıcılar eski yetkiyle çalışır ve düzeltmek için ayrıca
 * "şablonu uygula" demek gerekirdi.
 *
 * Rol ne diyorsa o geçerli. Kullanıcının kendi satırları YALNIZCA
 * İSTİSNADIR: rolün vermediğini ekler, verdiğini keser
 * (bkz. UserPermissionResolver).
 *
 * Rolün şablonu KAYNAK GENELİNDEDİR (kaynak => aksiyonlar): "switchleri
 * görebilir" gibi. Tek tek hangi switch olduğu kişiye özel istisnalarla
 * söylenir; rol, kaç switch olduğunu bilmek zorunda değil.
 */
class RolePermissions
{
    /** Rol bu kaynakta bu aksiyonu KAYNAK GENELİNDE veriyor mu? */
    public function grants(?Role $role, string $resource, string $action): bool
    {
        /*
         * Yöneticinin şablonu YOKTUR: bütün kontrolleri zaten geçer. Şablon
         * tutsaydı, listede olmayan bir yetki "yönetici bunu yapamaz" gibi
         * görünürdü.
         */
        if ($role === null || $role->is_admin) {
            return false;
        }

        return in_array($action, (array) ($role->abilities()[$resource] ?? []), true);
    }
}
