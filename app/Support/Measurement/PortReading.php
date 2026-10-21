<?php

declare(strict_types=1);

namespace App\Support\Measurement;

use App\Enums\MeasurementSource;

/**
 * Bir switchin bütün arayüz ölçümleri ve onları hangi kaynağın verdiği.
 */
readonly class PortReading
{
    /**
     * @param  array<int, PortMeasurement>  $measurements  ifIndex => ölçüm
     */
    public function __construct(
        public MeasurementSource $source,
        public array $measurements,
    ) {}

    /**
     * Grafik için geçmiş veri yalnızca izleme sisteminde vardır; cihaz
     * kaynaklarında panel kendi örneklemesini tutar.
     */
    public function needsSampling(): bool
    {
        return $this->source->readsDevice();
    }

    /**
     * @return array<int, array{in: ?float, out: ?float}>
     */
    public function rates(): array
    {
        $rates = [];

        foreach ($this->measurements as $ifIndex => $measurement) {
            $rates[$ifIndex] = ['in' => $measurement->inBps, 'out' => $measurement->outBps];
        }

        return $rates;
    }
}
