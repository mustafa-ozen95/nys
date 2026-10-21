<?php

declare(strict_types=1);

namespace App\Support\Measurement;

/**
 * Bir cihazdan okunan anlık ölçümler.
 *
 * Kaynağı ne olursa olsun (Zabbix, NETCONF, SNMP) panel bu biçimi görür.
 * Okunamayan alan null kalır ve kayıtlı değeri EZMEZ: bir kaynağın geçici
 * olarak boş dönmesi envanteri boşaltmamalı.
 */
readonly class DeviceMeasurement
{
    public function __construct(
        public bool $reachable,
        public ?int $uptimeSeconds = null,
        public ?int $cpuUsage = null,
        public ?int $memoryUsage = null,
        public ?int $temperature = null,
        public ?string $model = null,
        public ?string $serialNumber = null,
        public ?string $junosVersion = null,
    ) {}

    /**
     * Kaynak yanıt verdi ama cihaz erişilemez durumda.
     */
    public static function unreachable(): self
    {
        return new self(reachable: false);
    }

    /**
     * Veritabanına yazılacak alanlar. Null olanlar dışarıda kalır.
     *
     * @return array<string, int|string>
     */
    public function attributes(): array
    {
        return array_filter([
            'uptime_seconds' => $this->uptimeSeconds,
            'cpu_usage' => $this->cpuUsage,
            'memory_usage' => $this->memoryUsage,
            'temperature' => $this->temperature,
            'model' => $this->model,
            'serial_number' => $this->serialNumber,
            'junos_version' => $this->junosVersion,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
