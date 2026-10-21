<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Format\Bandwidth;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{labels: array<int, int>, in: array<int, float>, out: array<int, float>, trend: bool} $resource
 */
class TrafficSeriesResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $peak = max(
            [0, ...$this->resource['in'], ...$this->resource['out']],
        );

        return [
            'labels' => $this->resource['labels'],
            'series' => [
                // Renk adı burada verilir; tarayıcı onu tasarım token'ına çevirir.
                ['label' => 'Gelen', 'tone' => 'accent', 'values' => $this->resource['in']],
                ['label' => 'Giden', 'tone' => 'blue', 'values' => $this->resource['out']],
            ],
            // Eksen birimi sunucuda seçilir; tarayıcı yalnızca böler ve yazar.
            'scale' => Bandwidth::scaleFor($peak),
            'aggregated' => $this->resource['trend'],
        ];
    }
}
