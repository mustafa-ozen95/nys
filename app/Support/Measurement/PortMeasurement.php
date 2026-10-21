<?php

declare(strict_types=1);

namespace App\Support\Measurement;

use App\Enums\Duplex;
use App\Enums\PortStatus;

/**
 * Bir arayüzün anlık ölçümü. Yapılandırma değil DURUM taşır: açıklama, VLAN
 * üyeliği ve hız ayarı buraya girmez, onlar NETCONF envanterinden gelir.
 */
readonly class PortMeasurement
{
    public function __construct(
        public int $ifIndex,
        public ?PortStatus $operStatus = null,
        public ?int $speedMbps = null,
        public ?Duplex $duplex = null,
        public ?float $inBps = null,
        public ?float $outBps = null,
    ) {}

    /**
     * Port kaydına yazılacak alanlar. Trafik veritabanında tutulmaz; o yüzden
     * burada yoktur.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return array_filter([
            'oper_status' => $this->operStatus,
            'speed_mbps' => $this->speedMbps,
            'duplex' => $this->duplex,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
