<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToDeviceWrite;
use App\Http\Requests\Switching\LoginUserRequest;
use App\Models\NetworkSwitch;
use App\Models\SwitchLoginUser;
use App\Services\ActivityRecorder;
use App\Services\Netconf\LoginUserConfigurator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Cihaz üzerindeki yerel giriş hesapları.
 *
 * Bu hesaplar panel kullanıcılarından ayrıdır: switche konsoldan, SSH'tan ya
 * da panel dışından bağlananlar bunları kullanır. Paneldeki bir hesabın
 * silinmesi cihazdakini kapatmaz — bu ekran o boşluğu kapatır.
 *
 * Parola cihaza gider, panelde saklanmaz.
 */
class SwitchLoginUserController extends Controller implements HasMiddleware
{
    use RespondsToDeviceWrite;

    public function __construct(
        private LoginUserConfigurator $users,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:configure,switch')];
    }

    public function store(LoginUserRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        return $this->queueWrite(
            $switch,
            LoginUserConfigurator::class,
            'create',
            [$request->string('name')->toString(), $request->string('class')->toString(), $request->string('full_name')->trim()->toString() ?: null, $request->filled('uid') ? $request->integer('uid') : null, $request->string('password')->toString() ?: null],
            'cihaz hesabı eklendi',
        );
    }

    public function update(
        LoginUserRequest $request,
        NetworkSwitch $switch,
        SwitchLoginUser $loginUser,
    ): RedirectResponse {
        return $this->queueWrite(
            $switch,
            LoginUserConfigurator::class,
            'update',
            [$loginUser, $request->string('class')->toString(), $request->string('full_name')->trim()->toString() ?: null, $request->string('password')->toString() ?: null],
            'cihaz hesabı güncellendi',
        );
    }

    public function destroy(NetworkSwitch $switch, SwitchLoginUser $loginUser): RedirectResponse
    {
        /*
         * Cihaz bu silmeyi kabul eder ve panel bir daha bağlanamaz. Geri
         * alınabilir commit iki dakika sonra kurtarır; o iki dakika boyunca
         * switch yönetilemez. İstek cihaza hiç gitmez.
         */
        if ($loginUser->is_panel_account) {
            return back()->with('error', 'Panel cihaza bu hesapla bağlanıyor; silinemez.');
        }

        return $this->queueWrite(
            $switch,
            LoginUserConfigurator::class,
            'delete',
            [$loginUser],
            'cihaz hesabı silindi',
        );
    }

    protected function recorder(): ActivityRecorder
    {
        return $this->activity;
    }

    protected function indexRoute(): string
    {
        return 'switches.settings';
    }
}
