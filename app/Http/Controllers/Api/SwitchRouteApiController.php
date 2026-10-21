<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\RouteResource;
use App\Models\NetworkSwitch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Bir switchin statik route'ları.
 *
 * Yetki route kaynağının kendisindedir ("switch_routes"): switchi görebilen
 * biri route tablosunu göremeyebilir. Jeton ekranının verebildiği bu yetkinin
 * karşılığı bu uçtur.
 */
class SwitchRouteApiController extends Controller
{
    public function __invoke(Request $request, NetworkSwitch $switch): AnonymousResourceCollection
    {
        $this->authorize('viewSwitchRoutes', $switch);

        return RouteResource::collection(
            $switch->routes()->orderBy('destination')->get(),
        );
    }
}
