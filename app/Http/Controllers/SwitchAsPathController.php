<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Policy\AsPathRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchAsPath;
use App\Services\ActivityRecorder;
use App\Services\Netconf\PolicyStatementConfigurator;
use App\Services\Switching\DependencyGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * policy-options as-path tanımları.
 *
 * Politika terimi "from as-path <ad>" ile bunlara atıfta bulunuyorsa o tanım
 * önceden bulunmalıdır; community'de olduğu gibi tanımsız ada atıf commit'i
 * düşürür. Bu ekran o tanımı yapar.
 *
 * Yetki politikalarla aynıdır: as-path tek başına trafiği değiştirmez, ancak
 * bir politikadan kullanıldığında etkilidir.
 */
class SwitchAsPathController extends Controller implements HasMiddleware
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

    public function store(AsPathRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        $name = $request->string('name')->toString();

        return $this->queueWrite(
            $switch,
            PolicyStatementConfigurator::class,
            'createAsPath',
            [$name, $request->path()],
            "as-path {$name} eklendi",
        );
    }

    /**
     * Ad cihazda kimliktir; düzenlemede yalnızca ifade değişir.
     */
    public function update(AsPathRequest $request, NetworkSwitch $switch, SwitchAsPath $asPath): RedirectResponse
    {
        return $this->queueWrite(
            $switch,
            PolicyStatementConfigurator::class,
            'updateAsPath',
            [$asPath, $request->path()],
            "as-path {$asPath->name} güncellendi",
        );
    }

    public function destroy(NetworkSwitch $switch, SwitchAsPath $asPath): RedirectResponse
    {
        $bound = $this->dependencies->forAsPath($asPath);

        // Bir terim buna atıfta bulunuyorsa cihaz commit'i reddeder.
        if ($bound !== []) {
            return back()->with('error', $this->dependencies->message("As-path {$asPath->name}", $bound));
        }

        $name = $asPath->name;

        return $this->queueWrite(
            $switch,
            PolicyStatementConfigurator::class,
            'deleteAsPath',
            [$asPath],
            "as-path {$name} silindi",
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
