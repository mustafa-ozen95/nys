<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PortResource;
use App\Models\NetworkSwitch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Bir switchin portları.
 *
 * Yetki port kaynağının kendisindedir ("switch_ports"): switchi görebilen
 * biri portlarını göremeyebilir.
 */
class SwitchPortApiController extends Controller
{
    public function __invoke(Request $request, NetworkSwitch $switch): AnonymousResourceCollection
    {
        $this->authorize('viewSwitchPorts', $switch);

        return PortResource::collection(
            $switch->ports()->with('vlans')->orderBy('if_index')->get(),
        );
    }
}
