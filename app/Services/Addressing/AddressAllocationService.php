<?php

declare(strict_types=1);

namespace App\Services\Addressing;

use App\Models\AddressBlock;
use App\Support\Net\Cidr;

/**
 * Bir bloğun altındaki boş alt ağ yuvalarını bulur.
 *
 * Bölme SUNUCUDA yapılır ve doluluk sayısal aralıkla denetlenir. Dolu
 * yuvaları metin karşılaştırmasıyla ("startsWith") bulmak yanlış sonuç
 * verir: 192.0.2.1 metni 192.0.2.10 ile de başlar.
 */
class AddressAllocationService
{
    /**
     * @return array<int, array{cidr: string, network: string, available: bool, usable: int}>
     */
    public function slots(AddressBlock $parent, int $prefixLength): array
    {
        $full = $parent->subnets()
            ->get(['range_start', 'range_end'])
            ->map(fn (AddressBlock $subnet): array => [$subnet->range_start, $subnet->range_end])
            ->all();

        return collect($parent->toCidr()->splitInto($prefixLength))
            ->map(fn (Cidr $slot): array => [
                'cidr' => $slot->toString(),
                'network' => $slot->network,
                'available' => ! $this->overlaps($slot, $full),
                'usable' => $slot->usableCount(),
            ])
            ->all();
    }

    /**
     * Kök blok için seçilebilecek maskeler. Kullanılabilir adres sayısı
     * etikette yazar; kullanıcı /24'ün ne kadar olduğunu hesaplamak zorunda
     * kalmaz.
     *
     * @return array<int|string, string>
     */
    public function maskOptions(int $from = 8, int $to = 32): array
    {
        return $this->labelled(range($from, $to));
    }

    /**
     * Bir bloğun altında seçilebilecek maskeler: üst bloktan dar olmalı.
     *
     * @return array<int|string, string>
     */
    public function subnetMaskOptions(AddressBlock $parent): array
    {
        $start = $parent->prefix_length + 1;
        $end = $parent->toCidr()->maxPrefixLength();

        return $start > $end ? [] : $this->labelled(range($start, $end));
    }

    /**
     * @param  array<int, int>  $prefixes
     * @return array<int|string, string>
     */
    private function labelled(array $prefixes): array
    {
        return collect($prefixes)
            ->mapWithKeys(function (int $prefix): array {
                $usable = Cidr::make('0.0.0.0', $prefix)->usableCount();

                return [$prefix => "/{$prefix} · ".number_format($usable, 0, ',', '.').' adres'];
            })
            ->all();
    }

    /**
     * @param  array<int, array{0: ?int, 1: ?int}>  $taken
     */
    private function overlaps(Cidr $slot, array $taken): bool
    {
        $start = $slot->addressStart();
        $end = $slot->addressEnd();

        foreach ($taken as [$fullStart, $fullEnd]) {
            if ($fullStart === null || $fullEnd === null) {
                continue;
            }

            if ($start <= $fullEnd && $end >= $fullStart) {
                return true;
            }
        }

        return false;
    }
}
