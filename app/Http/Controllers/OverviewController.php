<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Switching\NetworkOverviewService;
use Illuminate\View\View;

/**
 * Yetkilendirme: ekran herkese açıktır ama içerik kullanıcıya göre daralır —
 * switchler visibleTo ile, etkinlik listesi viewAny kontrolüyle süzülür.
 */
class OverviewController extends Controller
{
    public function __construct(private NetworkOverviewService $overview) {}

    public function __invoke(): View
    {
        return view('overview', $this->overview->forUser(auth()->user()));
    }
}
