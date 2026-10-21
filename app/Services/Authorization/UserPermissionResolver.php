<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\PermissionEffect;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kayıt ve kaynak düzeyindeki yetki kararlarının tek merkezi.
 *
 * İKİ KATMAN VAR: rol ve kişi.
 *
 *   ROL   canlı okunur (bkz. RolePermissions). Rol ne diyorsa o geçerlidir;
 *         kullanıcıya kopyalanmaz. Kopya eskir: rolü düzenleyen yeni yetkiyi
 *         görür, kullanıcılar eski yetkiyle çalışırdı.
 *   KİŞİ  yalnızca İSTİSNA tutar: rolün vermediğini ekler (izin), verdiğini
 *         keser (yasak). Satırı olmayan yerde rol geçerlidir.
 *
 * Karar sırası (ilk eşleşen kazanır):
 *   1. Kişinin KAYDA ÖZEL satırı     -> izin / yasak
 *   2. Kişinin KAYNAK GENELİ satırı  -> izin / yasak
 *   3. Rolün kaynak geneli yetkisi   -> izin ver
 *   4. Eşleşme yok                   -> reddet (varsayılan kapalı)
 *
 * KİŞİ ROLDEN ÖNCE GELİR ve bu bilinçli: "bu kişide olmasın" demenin yolu
 * yoksa istisna diye bir şey yoktur.
 */
class UserPermissionResolver
{
    public function __construct(private RolePermissions $roles) {}

    public function allows(User $user, string $resource, string $action, ?int $recordId = null): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($this->yalnizcaYoneticiKaynagi($resource) || $this->basitModdaKapali($user, $resource, $action)) {
            return false;
        }

        $rows = $this->rowsFor($user, $resource, $action);

        if ($recordId !== null && $recordId !== UserPermission::ALL_RECORDS) {
            $record = $rows->firstWhere('resource_id', $recordId);

            if ($record !== null) {
                return $record->effect === PermissionEffect::Allow;
            }
        }

        $wildcard = $rows->firstWhere('resource_id', UserPermission::ALL_RECORDS);

        if ($wildcard !== null) {
            return $wildcard->effect === PermissionEffect::Allow;
        }

        return $this->roles->grants($user->role, $resource, $action);
    }

    /**
     * PANELİN YÖNETİMİ YALNIZCA YÖNETİCİDE.
     *
     * Ayarlar, hesaplar ve etkinlik
     * kaydı yönetici olmayana verilemez — ne rol şablonuyla ne kişisel
     * istisnayla. Liste config'te (permissions.yonetim_kaynaklari); cihaz
     * ekranları orada YOK, onlar işin kendisi.
     *
     * Karar burada duruyor çünkü üç giriş de (allows, allowsAny, scope)
     * buradan geçiyor; üç ayrı yere yazılsaydı biri unutulurdu.
     */
    private function yalnizcaYoneticiKaynagi(string $resource): bool
    {
        /*
         * Varsayılanlar GÜVENLİ TARAFA düşer ve bilinçli olarak burada da
         * yazılı: config önbelleğe alınmış bir kurulumda yeni anahtar
         * görünmeyebilir ve boş bir liste kuralı sessizce devre dışı
         * bırakırdı. Kapalı olması gereken bir kapının varsayılanı "açık"
         * olamaz.
         */
        return (bool) config('permissions.yonetim_yalnizca_yonetici', true)
            && in_array(
                $resource,
                (array) config('permissions.yonetim_kaynaklari', ['settings', 'users', 'logs']),
                true,
            );
    }

    /**
     * BASİT MODDA KAPALI BÖLÜM (bkz. config/basit_mod.php).
     *
     * Basit moddaki kişinin yetkisi "rolünün verdiği ∩ basit modda açık
     * olan"dır: kapatılan bölüm menüden kalkar ve adresi yazılsa da açılmaz.
     * Yalnızca DARALTIR. Yönetici bu kontrole gelmez (bütün kontrolleri
     * geçer, gelişmiş moda her zaman geçebilir). Üç giriş de (allows,
     * allowsAny, scope) buradan geçer — biri unutulursa menü kapalı ama
     * adres açık kalırdı.
     */
    private function basitModdaKapali(User $user, string $resource, string $action): bool
    {
        $bolum = config("basit_mod.bolumler.{$resource}");

        if (! is_array($bolum) || $user->etkinPanelModu()->isAdvanced()) {
            return false;
        }

        $gorur = (bool) ($bolum['goruntule'] ?? true);

        return $action === 'view' ? ! $gorur : (! $gorur || ! (bool) ($bolum['yonet'] ?? true));
    }

    /**
     * Kullanıcının kaynağa hiç erişimi var mı? Menü ve liste ekranları bunu sorar.
     */
    public function allowsAny(User $user, string $resource, string $action): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($this->yalnizcaYoneticiKaynagi($resource) || $this->basitModdaKapali($user, $resource, $action)) {
            return false;
        }

        $rows = $this->rowsFor($user, $resource, $action);
        $wildcard = $rows->firstWhere('resource_id', UserPermission::ALL_RECORDS);

        if ($wildcard !== null) {
            return $wildcard->effect === PermissionEffect::Allow
                || $this->allowedIds($rows)->isNotEmpty();
        }

        return $this->roles->grants($user->role, $resource, $action)
            || $this->allowedIds($rows)->isNotEmpty();
    }

    /**
     * Sorguyu kullanıcının görebildiği kayıtlarla sınırlar.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query, User $user, string $resource, string $action = 'view'): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        /* Panelin yönetimi yalnızca yöneticide; basit modda kapalı bölüm de boş döner. */
        if ($this->yalnizcaYoneticiKaynagi($resource) || $this->basitModdaKapali($user, $resource, $action)) {
            return $query->whereRaw('1 = 0');
        }

        $rows = $this->rowsFor($user, $resource, $action);
        $wildcard = $rows->firstWhere('resource_id', UserPermission::ALL_RECORDS);

        /*
         * Kişinin kaynak geneli satırı yoksa ROL konuşur. Süzme veritabanında
         * kalır: "hepsini getir sonra ekranda gizle" güvenlik değildir.
         */
        $genelIzin = $wildcard !== null
            ? $wildcard->effect === PermissionEffect::Allow
            : $this->roles->grants($user->role, $resource, $action);

        if ($genelIzin) {
            return $query->whereNotIn($query->qualifyColumn('id'), $this->deniedIds($rows));
        }

        // Genel izin yok: yalnızca kayda özel izinler geçerli.
        return $query->whereIn($query->qualifyColumn('id'), $this->allowedIds($rows));
    }

    /**
     * Yetki matrisi ekranını besleyen veri.
     *
     * @return array<string, array<string, array{wildcard: ?string, allowed: array<int, int>, denied: array<int, int>}>>
     */
    public function matrixFor(User $user): array
    {
        $matrix = [];

        foreach ($user->permissions as $permission) {
            $bucket = &$matrix[$permission->resource][$permission->action];

            $bucket ??= ['wildcard' => null, 'allowed' => [], 'denied' => []];

            if ($permission->resource_id === UserPermission::ALL_RECORDS) {
                $bucket['wildcard'] = $permission->effect->value;

                continue;
            }

            $key = $permission->effect === PermissionEffect::Allow ? 'allowed' : 'denied';
            $bucket[$key][] = $permission->resource_id;
        }

        return $matrix;
    }

    /**
     * Bir kaynağın izinlerini tek işlemde yeniden yazar.
     *
     * Satırlar İSTİSNADIR: rolün verdiğini burada yazmaya gerek yok, rol
     * canlı okunuyor (bkz. RolePermissions).
     *
     * @param  array<int, string>  $wildcardActions  kaynağın tamamına izin verilen aksiyonlar
     * @param  array<string, array<int, int>>  $allowedRecords  aksiyon => kayıt id'leri
     * @param  array<string, array<int, int>>  $deniedRecords  aksiyon => kayıt id'leri
     * @param  array<int, string>  $wildcardDenied  kaynağın tamamında yasaklanan aksiyonlar
     */
    public function syncResource(
        User $user,
        string $resource,
        array $wildcardActions,
        array $allowedRecords = [],
        array $deniedRecords = [],
        array $wildcardDenied = [],
    ): void {
        DB::transaction(function () use ($user, $resource, $wildcardActions, $allowedRecords, $deniedRecords, $wildcardDenied): void {
            $user->permissions()->where('resource', $resource)->delete();

            $rows = [];

            foreach ($wildcardActions as $action) {
                $rows[] = $this->row($resource, UserPermission::ALL_RECORDS, $action, PermissionEffect::Allow);
            }

            /* Kaynak geneli YASAK: rolün verdiğini bu kişide keser. */
            foreach ($wildcardDenied as $action) {
                $rows[] = $this->row($resource, UserPermission::ALL_RECORDS, $action, PermissionEffect::Deny);
            }

            foreach ($allowedRecords as $action => $ids) {
                foreach (array_unique($ids) as $id) {
                    $rows[] = $this->row($resource, (int) $id, $action, PermissionEffect::Allow);
                }
            }

            foreach ($deniedRecords as $action => $ids) {
                foreach (array_unique($ids) as $id) {
                    $rows[] = $this->row($resource, (int) $id, $action, PermissionEffect::Deny);
                }
            }

            if ($rows !== []) {
                $user->permissions()->createMany($rows);
            }
        });

        $this->flush($user);
    }

    /**
     * Yetki satırları model örneğinde önbelleklenir; bu metot onu geçersiz kılar.
     */
    public function flush(User $user): void
    {
        $user->unsetRelation('permissions');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $resource, int $recordId, string $action, PermissionEffect $effect): array
    {
        return [
            'resource' => $resource,
            'resource_id' => $recordId,
            'action' => $action,
            'effect' => $effect,
        ];
    }

    /**
     * Satırlar model örneğinin ilişki önbelleğinden okunur: istek başına tek
     * sorgu yapılır, model yenilendiğinde önbellek kendiliğinden düşer.
     *
     * @return Collection<int, UserPermission>
     */
    private function rowsFor(User $user, string $resource, string $action): Collection
    {
        return $user->permissions
            ->where('resource', $resource)
            ->where('action', $action);
    }

    /**
     * @param  Collection<int, UserPermission>  $rows
     * @return Collection<int, int>
     */
    private function allowedIds(Collection $rows): Collection
    {
        return $rows
            ->where('effect', PermissionEffect::Allow)
            ->where('resource_id', '!=', UserPermission::ALL_RECORDS)
            ->pluck('resource_id')
            ->values();
    }

    /**
     * @param  Collection<int, UserPermission>  $rows
     * @return Collection<int, int>
     */
    private function deniedIds(Collection $rows): Collection
    {
        return $rows
            ->where('effect', PermissionEffect::Deny)
            ->where('resource_id', '!=', UserPermission::ALL_RECORDS)
            ->pluck('resource_id')
            ->values();
    }
}
