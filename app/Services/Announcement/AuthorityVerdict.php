<?php

declare(strict_types=1);

namespace App\Services\Announcement;

use App\Enums\PrefixAuthorizationState;

/**
 * Tek bir kaynağın tek bir prefix hakkındaki kararı.
 */
final readonly class AuthorityVerdict
{
    public function __construct(
        public string $authority,
        public string $label,
        public PrefixAuthorizationState $state,
        public string $detail,
    ) {}

    public function approves(): bool
    {
        return $this->state->allowsSending();
    }
}
