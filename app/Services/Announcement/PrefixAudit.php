<?php

declare(strict_types=1);

namespace App\Services\Announcement;

use App\Enums\PrefixAuthorizationPolicy;
use App\Enums\PrefixAuthorizationState;

/**
 * Tek bir prefixin bütün kaynaklardaki durumu.
 *
 * Onay ölçütünü politika belirler (bkz. PrefixAuthorizationPolicy); bu sınıf
 * "kaç kaynak yeter" sorusunu kendi cevaplamaz.
 *
 * Onaylanan bir blokta bile EKSİK kaynak olabilir: ROA var ama IRR kaydı yok
 * gibi. Eksik gizlenmez — karşı taraf prefix filtresini çoğunlukla IRR'den
 * üretir ve eksik kayıt talebin cevapsız kalmasının sebebidir. Gönderimi
 * durdurmaz ama operatöre söylenir.
 */
final readonly class PrefixAudit
{
    /**
     * @param  array<int, AuthorityVerdict>  $verdicts
     */
    public function __construct(
        public string $prefix,
        public array $verdicts,
        public PrefixAuthorizationPolicy $policy = PrefixAuthorizationPolicy::Any,
    ) {}

    public function approved(): bool
    {
        return $this->policy->satisfiedBy($this->verdicts);
    }

    /**
     * Onaylamayan kararlar. Politikaya göre bunlar gönderimi durdurabilir de
     * durdurmayabilir de; ikisinde de operatöre gösterilir.
     *
     * @return array<int, AuthorityVerdict>
     */
    public function gaps(): array
    {
        return array_values(array_filter(
            $this->verdicts,
            fn (AuthorityVerdict $verdict): bool => ! $verdict->approves(),
        ));
    }

    public function hasGaps(): bool
    {
        return $this->gaps() !== [];
    }

    /**
     * Ekranda satırın rozetini belirleyen durum.
     */
    public function state(): PrefixAuthorizationState
    {
        if ($this->approved()) {
            return PrefixAuthorizationState::Approved;
        }

        foreach ([PrefixAuthorizationState::Rejected, PrefixAuthorizationState::Missing] as $state) {
            foreach ($this->gaps() as $gap) {
                if ($gap->state === $state) {
                    return $state;
                }
            }
        }

        return PrefixAuthorizationState::Unknown;
    }

    public function reason(): string
    {
        return collect($this->gaps())
            ->map(fn (AuthorityVerdict $verdict): string => "{$verdict->label}: {$verdict->detail}")
            ->implode(' · ');
    }
}
