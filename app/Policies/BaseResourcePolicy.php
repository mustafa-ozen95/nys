<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Kaynak bazlı policy'lerin ortak gövdesi. Beş policy'de tekrarlanacak
 * yirmi beş metodu tek yerde toplar; alt sınıflar yalnızca kaynak anahtarını
 * ve varsa kaynağa özgü ek kuralları tanımlar.
 */
abstract class BaseResourcePolicy
{
    public function __construct(protected UserPermissionResolver $resolver) {}

    abstract protected function resource(): string;

    public function viewAny(User $user): bool
    {
        return $this->resolver->allowsAny($user, $this->resource(), 'view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allows($user, 'view', $model);
    }

    public function create(User $user): bool
    {
        return $this->resolver->allows($user, $this->resource(), 'create');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allows($user, 'update', $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allows($user, 'delete', $model);
    }

    protected function allows(User $user, string $action, Model $model): bool
    {
        return $this->resolver->allows($user, $this->resource(), $action, (int) $model->getKey());
    }

    /**
     * Dış dünyaya etki eden yetenekler ("connect", "configure") Gate::before
     * tarafından bypass EDİLMEZ; yöneticinin izin kısmını geçmesi burada
     * karşılanır. Olgusal koşulları (kimlik bilgisi, erişilebilirlik) alt
     * sınıf ayrıca kontrol eder.
     */
    protected function permitsPrivileged(User $user, string $action, Model $model): bool
    {
        return $user->isAdmin()
            || ($this->view($user, $model) && $this->allows($user, $action, $model));
    }

    protected function permitsConnect(User $user, Model $model): bool
    {
        return $this->permitsPrivileged($user, 'connect', $model);
    }

    /**
     * Bağlantı bilgilerini DOSYAYA çıkarma yetkisi.
     *
     * "Görüntüle" bunu KAPSAMAZ ve switchlerdeki "sır göster" de kapsamaz:
     * ekranda görülen tek bir sır orada kalır, dosyaya yazılan liste panelin
     * dışına çıkar ve gittiği yerde panelin denetiminde değildir. Ayrı bir
     * yetki, ayrı bir karardır.
     */
    public function export(User $user, Model $model): bool
    {
        return $this->permitsPrivileged($user, 'export', $model);
    }

    /**
     * Çıktı ekranına girebilir mi?
     *
     * Yalnızca KAPIYI açar; hangi satırın dosyaya gireceğine tek tek export()
     * karar verir.
     */
    public function exportAny(User $user): bool
    {
        return $user->isAdmin() || $this->resolver->allowsAny($user, $this->resource(), 'export');
    }
}
