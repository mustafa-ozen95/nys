<?php

declare(strict_types=1);

namespace App\Support\Measurement;

use App\Enums\MeasurementSource;

/**
 * Bir cihaz ölçümü ve onu hangi kaynağın verdiği.
 *
 * Kaynak bilgisi taşınır çünkü panel bunu göstermek zorundadır: kullanıcı
 * "%6 CPU" değerinin izleme sisteminden mi yoksa cihazın kendisinden mi
 * geldiğini bilmeli.
 */
readonly class DeviceReading
{
    public function __construct(
        public MeasurementSource $source,
        public DeviceMeasurement $measurement,
    ) {}
}
