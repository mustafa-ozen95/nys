<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\IpAddressResource;
use App\Models\NetworkSwitch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Bir switchin IP adresleri.
 */
class SwitchIpAddressApiController extends Controller
{
    public function __invoke(Request $request, NetworkSwitch $switch): AnonymousResourceCollection
    {
        $this->authorize('viewSwitchIpAddresses', $switch);

        return IpAddressResource::collection(
            $switch->ipAddresses()->orderBy('interface_name')->get(),
        );
    }
}
