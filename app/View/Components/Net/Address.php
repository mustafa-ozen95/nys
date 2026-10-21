<?php

declare(strict_types=1);

namespace App\View\Components\Net;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * IP adresini prefix'iyle birlikte tek biçimde gösterir.
 */
class Address extends Component
{
    public string $value;

    public function __construct(?string $address, ?int $prefixLength = null)
    {
        $this->value = match (true) {
            $address === null || $address === '' => '—',
            $prefixLength === null => $address,
            default => "{$address}/{$prefixLength}",
        };
    }

    public function render(): View
    {
        return view('components.net.value');
    }
}
