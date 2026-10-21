<?php

declare(strict_types=1);

namespace App\Services\Bgp;

use App\Models\BgpNeighbor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * BGP genel bakış ekranının hazır verisi.
 */
class BgpOverviewService
{
    /**
     * @return array<string, mixed>
     */
    public function screen(User $user, string $query, string $selectedUuid): array
    {
        $neighbors = BgpNeighbor::query()
            ->visibleTo($user)
            ->with(['switch', 'group.families'])
            ->when($query !== '', fn (Builder $builder) => $this->search($builder, $query))
            ->orderBy('address')
            ->get();

        return [
            'neighbors' => $neighbors,
            'selected' => $neighbors->firstWhere('uuid', $selectedUuid),
            'query' => $query,
            'summary' => $this->summary($neighbors),
        ];
    }

    /**
     * @param  Builder<BgpNeighbor>  $builder
     */
    private function search(Builder $builder, string $query): void
    {
        $builder->where(fn (Builder $inner) => $inner
            ->where('address', 'like', "%{$query}%")
            ->orWhere('description', 'like', "%{$query}%")
            ->orWhere('peer_asn', 'like', "%{$query}%")
            ->orWhereHas('group', fn (Builder $group) => $group
                ->where('name', 'like', "%{$query}%")
                ->orWhere('peer_asn', 'like', "%{$query}%"))
            ->orWhereHas('switch', fn (Builder $switch) => $switch
                ->where('hostname', 'like', "%{$query}%")));
    }

    /**
     * @param  Collection<int, BgpNeighbor>  $neighbors
     * @return array<string, int>
     */
    private function summary(Collection $neighbors): array
    {
        return [
            'total' => $neighbors->count(),
            'established' => $neighbors->filter(fn (BgpNeighbor $n): bool => $n->state->isUp())->count(),
        ];
    }
}
