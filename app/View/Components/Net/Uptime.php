<?php

declare(strict_types=1);

namespace App\View\Components\Net;

use App\Support\Format\Duration;
use Illuminate\View\Component;
use Illuminate\View\View;

class Uptime extends Component
{
    public string $value;

    public function __construct(int|float|null $seconds)
    {
        $this->value = Duration::format($seconds);
    }

    public function render(): View
    {
        return view('components.net.value');
    }
}
