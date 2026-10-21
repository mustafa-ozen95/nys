<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class UserPolicy extends BaseResourcePolicy
{
    protected function resource(): string
    {
        return 'users';
    }

    /**
     * Yetki matrisini yalnızca yönetici düzenleyebilir.
     *
     * Gate::before admin'i zaten geçirdiği için buraya ulaşan herkes
     * yönetici değildir; kural bilinçli olarak katıdır.
     */
    public function managePermissions(User $user, User $target): bool
    {
        return false;
    }

    /**
     * YÖNETİCİ HESABINA YALNIZCA YÖNETİCİ DOKUNABİLİR.
     *
     * "Kullanıcıları düzenle" yetkisi rolü değiştirme yetkisi değildi ve bu
     * zaten engelleniyordu — ama aynı sonuca ikinci bir kapıdan varılıyordu:
     * o yetkiye sahip biri bir YÖNETİCİNİN parolasını değiştirip onun
     * hesabıyla giriş yapabiliyordu. Rolü yükseltmeye gerek yok, rolü zaten
     * yüksek olan hesabı ele geçirmek yetiyordu. E-posta değiştirip parola
     * sıfırlama, ya da bütün yöneticileri askıya alıp paneli kilitlemek de
     * aynı kapıdan geçiyordu.
     *
     * Yine aynı kalıp: aynı sonuca giden iki yol, tek koruma.
     */
    public function update(User $user, Model $model): bool
    {
        return $this->outranksTarget($user, $model) && parent::update($user, $model);
    }

    /**
     * Kullanıcıyı YALNIZCA YÖNETİCİ siler; kendini silemez.
     *
     * Silme geri alınamaz bir işlem olduğu için yöneticiye ayrılmıştır;
     * yetki listesindeki "kullanıcı silme" izni tek başına yetmez.
     */
    public function delete(User $user, Model $model): bool
    {
        return $user->isAdmin()
            && ! $user->is($model);
    }

    /**
     * Hedef yönetici değilse ya da işlemi yapan da yöneticiyse serbest.
     *
     * Gate::before yöneticiyi zaten geçiriyor, yani burada normalde yalnızca
     * yönetici olmayanlar bulunur. Kontrol yine de açıkça yazılır: koruma
     * Gate sırasının bir yan etkisine bırakılmayacak kadar önemli.
     */
    private function outranksTarget(User $user, Model $model): bool
    {
        return ! ($model instanceof User && $model->isAdmin()) || $user->isAdmin();
    }
}
