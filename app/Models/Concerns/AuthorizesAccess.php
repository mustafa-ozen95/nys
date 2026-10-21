<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\Authorization\PermissionRegistry;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sorguyu kullanıcının yetkili olduğu kayıtlarla sınırlayan scope.
 *
 * Kararın kendisi UserPermissionResolver'dadır; burada yalnızca delegasyon
 * vardır, model iş mantığı taşımaz.
 */
trait AuthorizesAccess
{
    /** @param  Builder<static>  $query */
    public function scopeVisibleTo(Builder $query, User $user, string $action = 'view'): void
    {
        app(UserPermissionResolver::class)->scope(
            $query,
            $user,
            app(PermissionRegistry::class)->resourceFor($this),
            $action,
        );
    }
}
