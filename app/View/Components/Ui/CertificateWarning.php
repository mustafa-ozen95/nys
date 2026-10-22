<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\Services\CertificateStatus;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * TLS sertifikası bitmek üzereyken yöneticiye uyarı. Karar burada;
 * şablon yalnızca hazır durumu çizer.
 */
class CertificateWarning extends Component
{
    /** @var array<string, mixed>|null */
    public ?array $status;

    public bool $show;

    public function __construct()
    {
        $this->status = CertificateStatus::read();
        $this->show = CertificateStatus::warn() && (auth()->user()?->role?->is_admin);
    }

    public function render(): View
    {
        return view('components.ui.certificate-warning');
    }
}
