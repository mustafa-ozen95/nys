<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Switching\NetworkSwitchRequest;
use App\Models\NetworkSwitch;
use App\Services\ActivityRecorder;
use App\Services\Switching\SwitchCredentialService;
use App\Services\Switching\SwitchNavigationService;
use App\Services\Switching\SwitchViewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class NetworkSwitchController extends Controller implements HasMiddleware
{
    public function __construct(
        private SwitchCredentialService $credentials,
        private ActivityRecorder $activity,
        private SwitchViewService $viewData,
        private SwitchNavigationService $navigation,
    ) {}

    /**
     * Yetkilendirme Policy'de yapılır; burada yalnızca hangi metodun hangi
     * yeteneği gerektirdiği bildirilir.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.NetworkSwitch::class, only: ['index']),
            new Middleware('can:view,switch', only: ['show']),
            new Middleware('can:create,'.NetworkSwitch::class, only: ['store']),
            new Middleware('can:update,switch', only: ['update']),
            new Middleware('can:delete,switch', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $switches = NetworkSwitch::query()
            ->visibleTo($request->user())
            ->search($request->string('q')->toString() ?: null)
            ->orderByRole()
            ->orderBySeverity()
            ->orderBy('hostname')
            ->get();

        return view('switches.index', [
            'switches' => $switches,
            'query' => $request->string('q')->toString(),
        ] + $this->viewData->form(null));
    }

    public function show(Request $request, NetworkSwitch $switch): View
    {
        $switch->load(['credentials', 'ports', 'vlans']);

        return view('switches.show', $this->viewData->detail($switch) + [
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'overview'),
        ] + $this->viewData->form($switch));
    }

    public function store(NetworkSwitchRequest $request): RedirectResponse
    {
        $switch = DB::transaction(function () use ($request): NetworkSwitch {
            $switch = NetworkSwitch::create($request->switchAttributes());

            $this->credentials->sync($switch, $request->credentialAttributes());
            $this->activity->created($switch, "Switch eklendi: {$switch->hostname}");

            return $switch;
        });

        return redirect()
            ->route('switches.show', $switch)
            ->with('status', "{$switch->hostname} eklendi.");
    }

    public function update(NetworkSwitchRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        DB::transaction(function () use ($request, $switch): void {
            $switch->update($request->switchAttributes());

            $this->credentials->sync($switch, $request->credentialAttributes());
            $this->activity->updated($switch, "Switch güncellendi: {$switch->hostname}");
        });

        return redirect()
            ->route('switches.show', $switch)
            ->with('status', "{$switch->hostname} güncellendi.");
    }

    public function destroy(Request $request, NetworkSwitch $switch): RedirectResponse
    {
        $hostname = $switch->hostname;

        DB::transaction(function () use ($switch, $hostname): void {
            $this->activity->deleted($switch, "Switch silindi: {$hostname}");

            $switch->delete();
        });

        return redirect()
            ->route('switches.index')
            ->with('status', "{$hostname} silindi.");
    }
}
