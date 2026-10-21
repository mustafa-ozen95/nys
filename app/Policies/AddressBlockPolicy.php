<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AddressBlock;
use App\Models\User;
use App\Services\Addressing\AssignmentRights;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Database\Eloquent\Model;

class AddressBlockPolicy extends BaseResourcePolicy
{
    public function __construct(UserPermissionResolver $resolver, private AssignmentRights $rights)
    {
        parent::__construct($resolver);
    }

    protected function resource(): string
    {
        return 'addressing';
    }

    /**
     * Blok silinirken atamasının cihazdaki karşılığı da (route, geçit adresi,
     * prefix list satırı) çıkarılır; bu, o cihaz nesnesinin silme yetkisini ister.
     */
    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model)
            && ! ($model instanceof AddressBlock && $this->rights->missing($user, $model, null) !== []);
    }
}
