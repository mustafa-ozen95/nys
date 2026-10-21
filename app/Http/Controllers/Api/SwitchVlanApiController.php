<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\VlanResource;
use App\Models\NetworkSwitch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Bir switchin VLAN'ları.
 */
class SwitchVlanApiController extends Controller
{
    public function __invoke(Request $request, NetworkSwitch $switch): AnonymousResourceCollection
    {
        $this->authorize('viewSwitchVlans', $switch);

        return VlanResource::collection($switch->vlans()->orderBy('vlan_tag')->get());
    }
}
