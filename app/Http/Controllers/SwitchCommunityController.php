<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Policy\CommunityRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchCommunity;
use App\Services\ActivityRecorder;
use App\Services\Netconf\PolicyStatementConfigurator;
use App\Services\Switching\DependencyGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * BGP community tanımları.
 *
 * Politika terimi bir community'ye atıfta bulunuyorsa o community önceden
 * tanımlı olmalıdır; bu ekran o tanımı yapar.
 */
class SwitchCommunityController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private ActivityRecorder $activity,
        private PolicyStatementConfigurator $policies,
        private DependencyGuard $dependencies,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:createSwitchPolicies,switch', only: ['store']),
            new Middleware('can:updateSwitchPolicies,switch', only: ['update']),
            new Middleware('can:deleteSwitchPolicies,switch', only: ['destroy']),
        ];
    }

    public function store(CommunityRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->string('name')->toString();

        return $this->queueWrite(
            $switch,
            PolicyStatementConfigurator::class,
            'createCommunity',
            [$name, $request->memberList(), $request->invertMatch()],
            "community {$name} eklendi",
        );
    }

    public function update(
        CommunityRequest $request,
        NetworkSwitch $switch,
        SwitchCommunity $community,
    ): RedirectResponse {
        return $this->queueWrite(
            $switch,
            PolicyStatementConfigurator::class,
            'updateCommunity',
            [$community, $request->memberList(), $request->invertMatch()],
            "community {$community->name} güncellendi",
        );
    }

    public function destroy(NetworkSwitch $switch, SwitchCommunity $community): RedirectResponse
    {
        $bagli = $this->dependencies->forCommunity($community);

        if ($bagli !== []) {
            return back()->with(
                'error',
                $this->dependencies->message("Community {$community->name}", $bagli),
            );
        }

        $name = $community->name;

        return $this->queueWrite(
            $switch,
            PolicyStatementConfigurator::class,
            'deleteCommunity',
            [$community],
            "community {$name} silindi",
        );
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.policies';
    }
}
