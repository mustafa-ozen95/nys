<?php

declare(strict_types=1);

namespace App\Policies;

class AnnouncementRecipientPolicy extends BaseResourcePolicy
{
    protected function resource(): string
    {
        return 'announcements';
    }
}
