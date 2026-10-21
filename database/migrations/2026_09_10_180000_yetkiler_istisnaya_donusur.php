<?php

declare(strict_types=1);

use App\Enums\PermissionEffect;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Authorization\PermissionRegistry;
use App\Services\Authorization\RolePermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Kullanıcı yetkileri İSTİSNADIR.
 *
 * Rolün yetkileri hesap açılırken kullanıcıya KOPYALANMAZ. Kopya eskir: rolü
 * düzenleyen ekranda yeni yetkiyi görür, kullanıcılar eski yetkiyle çalışır
 * ve düzeltmenin yolu tek tek "şablonu uygula" demek olurdu. Rol canlı
 * okunur (bkz. RolePermissions); kullanıcının satırları yalnızca istisna
 * tutar.
 *
 * BU GÖÇ KİMSENİN ERİŞİMİNİ DEĞİŞTİRMEZ:
 *
 *   - Rolün verdiği ama kullanıcıda OLMAYAN yetki  -> açık YASAK satırı
 *   - Kullanıcıda olan ama rolün vermediği yetki   -> İZİN satırı olarak kalır
 *   - İkisinde de olan                             -> satır silinir (gereksiz)
 *
 * Yönetici rolündekilere dokunulmaz: onlar zaten bütün kontrolleri geçiyor.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roller = app(RolePermissions::class);
        $registry = app(PermissionRegistry::class);

        User::query()->with(['role', 'permissions'])->chunkById(200, function ($kullanicilar) use ($roller, $registry): void {
            foreach ($kullanicilar as $kullanici) {
                if ($kullanici->role?->is_admin) {
                    continue;
                }

                foreach ($registry->resourceKeys() as $kaynak) {
                    $this->tasi($kullanici, $roller, $kaynak, $registry->actionsFor($kaynak));
                }
            }
        });
    }

    /**
     * Geri alınabilir DEĞİL — ama zararsız.
     *
     * Önceki model kopyaya dayanır; kopyayı geri üretmek, bu göçten sonra
     * verilmiş istisnaları da şablona karıştırmak olurdu.
     */
    public function down(): void
    {
        // Kasıtlı olarak boş.
    }

    /** @param  list<string>  $aksiyonlar */
    private function tasi(User $kullanici, RolePermissions $roller, string $kaynak, array $aksiyonlar): void
    {
        $satirlar = $kullanici->permissions->where('resource', $kaynak);

        foreach ($aksiyonlar as $aksiyon) {
            $rolVeriyor = $roller->grants($kullanici->role, $kaynak, $aksiyon);
            $ait = $satirlar->where('action', $aksiyon);
            $genel = $ait->firstWhere('resource_id', UserPermission::ALL_RECORDS);

            if (! $rolVeriyor) {
                continue;
            }

            /*
             * Rol veriyor ama kullanıcıda kaynak geneli izin YOK: bugünkü
             * erişimi korumak için açık YASAK yazılır. Yazılmasaydı göç,
             * kimsenin vermediği bir yetkiyi açardı.
             */
            if ($genel === null) {
                UserPermission::query()->create([
                    'user_id' => $kullanici->getKey(),
                    'resource' => $kaynak,
                    'resource_id' => UserPermission::ALL_RECORDS,
                    'action' => $aksiyon,
                    'effect' => PermissionEffect::Deny,
                ]);

                continue;
            }

            /* Rol de veriyor, kullanıcıda da izin var: satır gereksiz. */
            if ($genel->effect === PermissionEffect::Allow) {
                $genel->delete();
            }
        }
    }
};
