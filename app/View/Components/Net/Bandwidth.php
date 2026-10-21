<?php

declare(strict_types=1);

namespace App\View\Components\Net;

use App\Support\Format\Bandwidth as Formatter;
use Illuminate\View\Component;
use Illuminate\View\View;

class Bandwidth extends Component
{
    public string $value;

    public function __construct(int|float|null $bps)
    {
        $this->value = Formatter::format($bps);
    }

    public function render(): View
    {
        return view('components.net.value');
    }
}
