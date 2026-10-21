<?php

declare(strict_types=1);

namespace App\Policies;

class ActivityLogPolicy extends BaseResourcePolicy
{
    protected function resource(): string
    {
        return 'logs';
    }
}
