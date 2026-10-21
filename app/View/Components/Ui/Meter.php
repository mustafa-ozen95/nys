<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\Enums\BadgeVariant;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Yüzdelik doluluk göstergesi. Uyarı eşikleri config/ui.php'den okunur,
 * şablona veya controller'a gömülmez.
 */
class Meter extends Component
{
    public ?float $percent;

    public BadgeVariant $variant;

    public string $display;

    public function __construct(
        int|float|null $value,
        public string $metric = 'cpu',
        ?string $unit = '%',
    ) {
        $this->percent = $value === null ? null : max(0.0, min(100.0, (float) $value));
        $this->variant = $this->variantFor($this->percent);
        $this->display = $this->percent === null
            ? '—'
            : rtrim(rtrim(number_format($this->percent, 1, ',', '.'), '0'), ',').$unit;
    }

    private function variantFor(?float $percent): BadgeVariant
    {
        if ($percent === null) {
            return BadgeVariant::Gray;
        }

        $thresholds = config("ui.thresholds.{$this->metric}", ['warning' => 70, 'danger' => 90]);

        return match (true) {
            $percent >= $thresholds['danger'] => BadgeVariant::Red,
            $percent >= $thresholds['warning'] => BadgeVariant::Yellow,
            default => BadgeVariant::Green,
        };
    }

    public function render(): View
    {
        return view('components.ui.meter');
    }
}
