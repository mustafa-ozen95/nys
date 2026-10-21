<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\BgpNeighborResource;
use App\Models\NetworkSwitch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Bir switchin BGP komşuları ve oturum durumları.
 *
 * Yetki BGP kaynağının kendisindedir ("switch_bgp"): switchi görebilen biri
 * komşularını göremeyebilir. Jeton ekranının verebildiği bu yetkinin
 * karşılığı bu uçtur.
 *
 * MD5 oturum anahtarı dönmez (bkz. BgpNeighborResource).
 */
class SwitchBgpApiController extends Controller
{
    public function __invoke(Request $request, NetworkSwitch $switch): AnonymousResourceCollection
    {
        $this->authorize('viewSwitchBgp', $switch);

        return BgpNeighborResource::collection(
            $switch->bgpNeighbors()->with('group')->orderBy('address')->get(),
        );
    }
}
