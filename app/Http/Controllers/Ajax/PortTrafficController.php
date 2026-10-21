<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Http\Controllers\Controller;
use App\Http\Requests\Switching\TrafficHistoryRequest;
use App\Http\Resources\TrafficSeriesResource;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Services\Switching\SwitchTrafficService;

class PortTrafficController extends Controller
{
    public function __construct(private SwitchTrafficService $traffic) {}

    public function __invoke(
        TrafficHistoryRequest $request,
        NetworkSwitch $switch,
        SwitchPort $port,
    ): TrafficSeriesResource {
        $this->authorize('view', $switch);
        abort_unless($port->switch_id === $switch->id, 404);

        return new TrafficSeriesResource(
            $this->traffic->history($port, $request->range()),
        );
    }
}
