<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BgpNeighbor;
use App\Services\Bgp\BgpOverviewService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Bütün switchlerdeki BGP komşuluklarının tek listesi.
 *
 * Burası SALT OKUNURDUR: bir komşuluk hangi switchte tanımlıysa oradan
 * yönetilir (switch → BGP ekranı). Yazma yolunun tek yerde durması,
 * yetkilendirmenin de tek yerde durması demektir.
 */
class BgpOverviewController extends Controller implements HasMiddleware
{
    public function __construct(private BgpOverviewService $overview) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:viewAny,'.BgpNeighbor::class)];
    }

    public function __invoke(Request $request): View
    {
        return view('bgp.index', $this->overview->screen(
            $request->user(),
            $request->string('q')->toString(),
            $request->string('neighbor')->toString(),
        ));
    }
}
