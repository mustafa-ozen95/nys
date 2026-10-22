<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ServerPasswordRotateJob;
use App\Models\Server;
use App\Models\ServerPasswordRotation;
use App\Services\Server\DriverSelector;
use App\Services\Server\RoundStatuses;
use App\Services\Server\ServerPasswordRotator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Sunucu parolalarının değiştirilmesi.
 *
 * Her sunucuya AYRI ve rastgele bir parola verilir; tek bir parolanın bütün
 * sahayı açması diye bir durum kalmaz.
 *
 * TUR KUYRUKTA KOŞAR. Makineye birkaç kez bağlanılıyor ve Windows tarafında
 * her çağrı kendi kabuğunu açıyor; isteği bekletmek tarayıcıyı zaman aşımına
 * düşürürdü. Ekran ilerlemeyi `durum` ucundan izler.
 *
 * Yetkilendirme sunucu başınadır ('rotate'): "düzenle" yetkisi bunu kapsamaz
 * — paneldeki kaydı değiştirmekle uzaktaki makinenin parolasını değiştirmek
 * aynı şey değildir.
 */
class ServerPasswordController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:rotate,server', only: ['rotate']),
            new Middleware('can:viewAny,'.Server::class, only: ['bulk', 'status']),
        ];
    }

    /** Tek sunucu. */
    public function rotate(Request $request, Server $server): RedirectResponse
    {
        $engel = app(DriverSelector::class)->engel($server);

        if ($engel !== null) {
            return back()->with('error', "{$server->short_name}: {$engel}");
        }

        ServerPasswordRotateJob::enqueue(
            (int) $server->id,
            (int) $request->user()->id,
            (string) $request->input('reason'),
        );

        return back()->with('status', "{$server->short_name} için parola güncellemesi sıraya alındı.");
    }

    /**
     * Seçilen sunucular — SIRAYLA.
     *
     * TEK TEK YETKİ SORULUR: toplu işlem, yetkisi olmayan bir sunucuya
     * dokunmanın kestirme yolu olmamalı. Atlananlar sessiz geçmez; kaç
     * tanesinin neden atlandığı ekrana döner.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $veri = $request->validate([
            'sunucular' => ['required', 'array', 'min:1'],
            'sunucular.*' => ['string'],
        ]);

        $selected = Server::query()
            ->visibleTo($request->user())
            ->whereIn('uuid', $veri['sunucular'])
            ->get();

        $selector = app(DriverSelector::class);

        $unauthorized = 0;
        $blocked = 0;
        $queue = [];

        foreach ($selected as $server) {
            if ($request->user()->cannot('rotate', $server)) {
                $unauthorized++;

                continue;
            }

            if ($selector->engel($server) !== null) {
                $blocked++;

                continue;
            }

            $queue[] = (int) $server->id;
        }

        if ($queue === []) {
            return back()->with('error', 'Hiçbir sunucunun parolası güncellenemedi: '.$this->skipped($unauthorized, $blocked).'.');
        }

        ServerPasswordRotateJob::enqueueChain(
            $queue,
            (int) $request->user()->id,
            (string) $request->input('reason'),
        );

        $extra = $this->skipped($unauthorized, $blocked);

        return back()->with(
            'status',
            count($queue).' sunucu sıraya alındı'.($extra === '' ? '.' : "; {$extra}."),
        );
    }

    /**
     * İlerleme.
     *
     * Yanıtın biçimi switch turlarıyla AYNIDIR: ekranda aynı betik çiziyor
     * (resources/js/components/rotation-progress.js). Hesap RoundStatuses'nde,
     * yani liste sayfasının ilk çizimiyle aynı yerde.
     */
    public function status(Request $request, RoundStatuses $statuses): JsonResponse
    {
        $lasting = $statuses->for(
            Server::query()
                ->visibleTo($request->user())
                ->with('latestPasswordRotation')
                ->get()
        );

        return response()->json([
            'calisiyor' => $lasting !== [],
            'cihazlar' => $lasting,
        ]);
    }

    /**
     * Yarıda kalmış turu kapatır: makinede duran kurtarma hesabını siler.
     *
     * Kuyruğa ATILMAZ, hemen çalışır: onarım tek bir makineye birkaç kimlik
     * denemekten ibaret ve kullanıcı sonucu görmek için düğmeye basıyor.
     */
    public function repair(Request $request, ServerPasswordRotation $rotation, ServerPasswordRotator $rotator): RedirectResponse
    {
        $server = $rotation->server;

        abort_if($server === null, 404);
        abort_if($request->user()->cannot('rotate', $server), 403);

        $result = $rotator->repair($rotation, $request->user());

        return back()->with(
            $result->recovery_active ? 'error' : 'status',
            "{$server->short_name}: ".($result->error ?? 'kurtarma hesabı temizlendi.'),
        );
    }

    private function skipped(int $unauthorized, int $blocked): string
    {
        $fragments = [];

        if ($blocked > 0) {
            $fragments[] = $blocked.' sunucuda eksik bilgi var (adres, kullanıcı ya da parola)';
        }

        if ($unauthorized > 0) {
            $fragments[] = $unauthorized.' sunucuda parola yetkiniz yok';
        }

        return implode(', ', $fragments);
    }
}
