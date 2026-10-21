<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityChannel;
use App\Http\Requests\ActivityLogFilterRequest;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Activity\ActivityChain;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class ActivityLogController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:viewAny,'.ActivityLog::class)];
    }

    public function __invoke(ActivityLogFilterRequest $request, ActivityChain $chain): View
    {
        $filters = $request->filters();

        return view('logs.index', [
            /*
             * SÜZME VERİTABANINDA: kayıt yetkisi yönetici olmayan birine de
             * verilebiliyor; süzülmediğinde o kişi göremediği cihazların
             * geçmişini de okuyordu (bkz. ActivityLog::scopeVisibleTo).
             */
            'logs' => ActivityLog::query()
                ->visibleTo($request->user())
                ->with(['user', 'subject'])
                ->filtered($filters)
                ->latest()
                ->paginate(config('ui.per_page'))
                ->withQueryString(),
            'filters' => $filters,
            'channels' => collect(ActivityChannel::cases())
                ->mapWithKeys(fn (ActivityChannel $case): array => [$case->value => $case->label()])
                ->prepend('Tüm kanallar', '')
                ->all(),
            /*
             * Süzgeç listesi de yetkiye bağlı: yönetici olmayan yalnızca
             * kendi hareketlerini görebildiği için listede tek ad durur.
             * Kadro listesi tek başına bilgidir. Değerler uuid taşır.
             */
            'users' => User::query()
                ->when(! $request->user()->isAdmin(), fn ($sorgu) => $sorgu->whereKey($request->user()->getKey()))
                ->orderBy('name')
                ->pluck('name', 'uuid')
                ->prepend('Tüm kullanıcılar', '')
                ->all(),

            /*
             * Bütünlük rozeti son doğrulamanın sonucundan basılır; ekran
             * her açılışta bütün kaydı yeniden özetlemez. Doğrulamayı
             * zamanlayıcı çalıştırır (network:log-verify).
             */
            'integrity' => $chain->lastVerification(),
        ]);
    }
}
