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
            new Middleware('can:rotate,server', only: ['dondur']),
            new Middleware('can:viewAny,'.Server::class, only: ['toplu', 'durum']),
        ];
    }

    /** Tek sunucu. */
    public function dondur(Request $request, Server $server): RedirectResponse
    {
        $engel = app(DriverSelector::class)->engel($server);

        if ($engel !== null) {
            return back()->with('error', "{$server->short_name}: {$engel}");
        }

        ServerPasswordRotateJob::kuyrukla(
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
    public function toplu(Request $request): RedirectResponse
    {
        $veri = $request->validate([
            'sunucular' => ['required', 'array', 'min:1'],
            'sunucular.*' => ['string'],
        ]);

        $secilen = Server::query()
            ->visibleTo($request->user())
            ->whereIn('uuid', $veri['sunucular'])
            ->get();

        $secici = app(DriverSelector::class);

        $yetkisiz = 0;
        $engelli = 0;
        $kuyruk = [];

        foreach ($secilen as $sunucu) {
            if ($request->user()->cannot('rotate', $sunucu)) {
                $yetkisiz++;

                continue;
            }

            if ($secici->engel($sunucu) !== null) {
                $engelli++;

                continue;
            }

            $kuyruk[] = (int) $sunucu->id;
        }

        if ($kuyruk === []) {
            return back()->with('error', 'Hiçbir sunucunun parolası güncellenemedi: '.$this->atlananlar($yetkisiz, $engelli).'.');
        }

        ServerPasswordRotateJob::sirayaAl(
            $kuyruk,
            (int) $request->user()->id,
            (string) $request->input('reason'),
        );

        $ek = $this->atlananlar($yetkisiz, $engelli);

        return back()->with(
            'status',
            count($kuyruk).' sunucu sıraya alındı'.($ek === '' ? '.' : "; {$ek}."),
        );
    }

    /**
     * İlerleme.
     *
     * Yanıtın biçimi switch turlarıyla AYNIDIR: ekranda aynı betik çiziyor
     * (resources/js/components/rotation-progress.js). Hesap RoundStatuses'nde,
     * yani liste sayfasının ilk çizimiyle aynı yerde.
     */
    public function durum(Request $request, RoundStatuses $durumlar): JsonResponse
    {
        $suren = $durumlar->icin(
            Server::query()
                ->visibleTo($request->user())
                ->with('latestPasswordRotation')
                ->get()
        );

        return response()->json([
            'calisiyor' => $suren !== [],
            'cihazlar' => $suren,
        ]);
    }

    /**
     * Yarıda kalmış turu kapatır: makinede duran kurtarma hesabını siler.
     *
     * Kuyruğa ATILMAZ, hemen çalışır: onarım tek bir makineye birkaç kimlik
     * denemekten ibaret ve kullanıcı sonucu görmek için düğmeye basıyor.
     */
    public function onar(Request $request, ServerPasswordRotation $rotation, ServerPasswordRotator $dondurucu): RedirectResponse
    {
        $sunucu = $rotation->server;

        abort_if($sunucu === null, 404);
        abort_if($request->user()->cannot('rotate', $sunucu), 403);

        $sonuc = $dondurucu->onar($rotation, $request->user());

        return back()->with(
            $sonuc->recovery_active ? 'error' : 'status',
            "{$sunucu->short_name}: ".($sonuc->error ?? 'kurtarma hesabı temizlendi.'),
        );
    }

    private function atlananlar(int $yetkisiz, int $engelli): string
    {
        $parcalar = [];

        if ($engelli > 0) {
            $parcalar[] = $engelli.' sunucuda eksik bilgi var (adres, kullanıcı ya da parola)';
        }

        if ($yetkisiz > 0) {
            $parcalar[] = $yetkisiz.' sunucuda parola yetkiniz yok';
        }

        return implode(', ', $parcalar);
    }
}
