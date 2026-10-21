<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\Enums\BadgeVariant;
use App\Enums\PortStatus;
use App\Enums\ServerStatus;
use App\Enums\SwitchStatus;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Durum noktası ve etiketi. Renk eşlemesi enum'un kendi sorumluluğundadır.
 */
class StatusDot extends Component
{
    public BadgeVariant $variant;

    public string $label;

    public function __construct(
        SwitchStatus|ServerStatus|PortStatus $status,
        public bool $showLabel = true,
    ) {
        $this->variant = $status->badge();
        $this->label = $status->label();
    }

    public function render(): View
    {
        return view('components.ui.status-dot');
    }
}
