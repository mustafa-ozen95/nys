<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Merkezden gelen tanınmayan kişi için YEREL hesap açar.
 *
 * NEDEN İSTEĞE BAĞLI: merkezde hesabı olmak, bu panelde işi olmak demek
 * değildir. Kapalıyken eşleşmeyen kimlik geri çevrilir ve hesabı yönetici
 * açar. Açmak bilinçli bir karardır ve o karar MERKEZDEDİR: merkezdeki
 * İstemciler ekranında uygulama başına verilir, panelde ikinci bir kopyası
 * tutulmaz.
 *
 * ROL MERKEZDEN GELİR ama merkez EMİR VERMEZ: gelen slug'ın burada aynı adlı
 * bir karşılığı varsa o verilir (rol adları merkezdekilerle bilerek aynı
 * tutulur), yoksa varsayılan rol verilir. Tanınmayan bir ad hiçbir zaman
 * "yetki yok"a değil, en dar role düşer — eksik veri yetki üretmemeli.
 *
 * YÖNETİCİLİK AYRI BİR KARAR. Merkezin yöneticisi kimlikleri yönetir; bu
 * panelin yöneticisi cihazlara yazabilir ve parola değiştirebilir. İkisi
 * kendiliğinden aynı kişi olmamalı: merkezde rol dağıtabilen biri, buradaki
 * hiçbir yöneticinin haberi olmadan kendini burada da yönetici yapabilirdi.
 * Bu yüzden yöneticilik yalnızca `identity.yonetici_devri` açıkken devrolur.
 *
 * PAROLA YAZILMAZ. Hesap rastgele, kimsenin bilmediği bir parolayla doğar:
 * bu kişinin giriş yolu merkezdir. Parolayla girmek isterse yerel bir
 * yönetici parola tanımlar.
 */
class AccountOpener
{
    /**
     * Bu kimlik için hesap AÇILABİLİR Mİ?
     *
     * KARARI MERKEZ VERİR: hangi uygulamada
     * hesabın kendiliğinden açılacağını merkezdeki İstemciler ekranı söyler
     * ve kimlik yanıtında `can_create_account` olarak gelir. Panel kendi
     * kopyasını TUTMAZ — aynı karar iki yerde durursa biri değişince öbürü
     * sessizce eski kalır ve hangisinin geçerli olduğu belirsizleşir.
     *
     * Üç koşul birden: merkez izin vermeli, merkez e-postayı DOĞRULANMIŞ
     * saymalı ve kişiye merkezde BİR ROL ATANMIŞ olmalı.
     *
     * Rolün şart olması bilinçli ve PANELİN KENDİ KARARI (yetki detayı
     * projenin içinde): merkezde yeni açılmış, henüz kimsenin bakmadığı bir
     * hesap rolsüz duruyor. Rolsüzü de içeri alsaydık, merkezde kendi kendine
     * kaydolan herkes bu panele varsayılan rolle düşerdi — oysa rol atamak,
     * o kişiye bakılmış olmasının kendisidir.
     *
     * @param  array{email_verified: bool, role?: ?string, can_create_account?: bool}  $kimlik
     */
    public function canOpen(array $identity): bool
    {
        return ($identity['can_create_account'] ?? false)
            && ($identity['email_verified'] ?? false)
            && filled($identity['role'] ?? null);
    }

    /**
     * @param  array{sub: string, name: string, email: string, email_verified: bool, role?: ?string}  $kimlik
     */
    public function open(array $identity): User
    {
        $role = $this->role($identity['role'] ?? null);

        $user = User::create([
            'name' => $identity['name'] !== '' ? $identity['name'] : $identity['email'],
            'email' => $identity['email'],

            /*
             * Bilinmeyen parola: hesabın kapısı merkezdir. Boş bırakmak ya da
             * öngörülebilir bir değer koymak, parolayla girişi açık bir kapı
             * hâline getirirdi.
             */
            'password' => Str::random(64),
            'role_id' => $role?->id,
            'status' => UserStatus::Active,
        ]);

        /* Yetki kopyalanmaz: rol canlı okunuyor (bkz. RolePermissions). */
        return $user->fresh();
    }

    /**
     * Hangi rolle doğacak: kararın tamamı burada.
     */
    private function role(?string $slug): ?Role
    {
        if (blank($slug)) {
            return Role::default();
        }

        $role = Role::query()->where('slug', $slug)->first();

        if ($role === null) {
            return Role::default();
        }

        // Yöneticilik yalnızca AÇIKÇA devrediliyorsa geçer.
        if ($role->is_admin && ! config('identity.yonetici_devri')) {
            return Role::default();
        }

        return $role;
    }

    /**
     * Kayda yazılacak açıklama: hangi rolün neden verildiği.
     *
     * Ekranda "hesap açıldı" demek yetmiyor; denetimde sorulan soru, o kişinin
     * bu yetkiye NASIL sahip olduğu.
     */
    public function description(User $user, array $identity): string
    {
        $incoming = $identity['role'] ?? null;
        $given = $user->role?->slug ?? 'rolsüz';

        if (blank($incoming)) {
            return "Merkezden hesap açıldı: {$user->email} · merkezde rolü yok, varsayılan rol verildi ({$given})";
        }

        return $incoming === $given
            ? "Merkezden hesap açıldı: {$user->email} · merkezdeki rolüyle ({$given})"
            : "Merkezden hesap açıldı: {$user->email} · merkezdeki rol '{$incoming}' devredilmedi, verilen rol: {$given}";
    }
}
