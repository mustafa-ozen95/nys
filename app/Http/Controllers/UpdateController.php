<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityChannel;
use App\Services\ActivityRecorder;
use App\Services\Settings\SettingsCatalog;
use App\Services\Update\UpdateApply;
use App\Services\Update\UpdateCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Güncelleme kontrolü: çalışan sürüm ve GitHub'daki son başarılı derleme.
 *
 * Kontrol her sabah kendiliğinden yapılır (update:check); düğme aynı
 * kontrolü hemen yapar. Yeni sürüm varsa "Şimdi güncelle" platformun deploy
 * hook'unu tetikler (UpdateApply); kurulumu platform yapar.
 *
 * Yetkilendirme: HasMiddleware ile `can:manageUpdate` (yalnızca yönetici).
 */
class UpdateController extends Controller implements HasMiddleware
{
    /** @var array<string, array{etiket: string, renk: string}> */
    private const STATUSES = [
        'guncel' => ['etiket' => 'güncel', 'renk' => 'green'],
        'yeni' => ['etiket' => 'yeni sürüm var', 'renk' => 'blue'],
        'ileride' => ['etiket' => 'son derlemeden ileride', 'renk' => 'yellow'],
        'ayrisik' => ['etiket' => 'main dalında değil', 'renk' => 'yellow'],
        'bilinmiyor' => ['etiket' => 'çalışan sürüm bilinmiyor', 'renk' => 'yellow'],
        'hata' => ['etiket' => 'kontrol edilemedi', 'renk' => 'red'],
    ];

    public function __construct(
        private SettingsCatalog $catalog,
        private UpdateCheck $audit,
        private UpdateApply $updater,
        private ActivityRecorder $activity,
    ) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:manageUpdate')];
    }

    public function index(): View
    {
        $last = $this->audit->last();
        $running = $this->audit->version();

        return view('settings.update', [
            'sections' => $this->catalog->tabs('update'),
            'depo' => $this->audit->repository(),
            'calisan' => $running,
            'calisanKisa' => $running === null ? 'bilinmiyor' : substr($running, 0, 7),
            'son' => $last === null ? null : [
                ...$last,
                'zaman' => self::time($last['zaman'] ?? null),
                'son_kisa' => substr((string) ($last['son'] ?? ''), 0, 7),
                'son_tarih' => self::time($last['son_tarih'] ?? null),
                'commitler' => array_map(fn (array $c): array => [
                    ...$c,
                    'kisa' => substr((string) $c['sha'], 0, 7),
                    'tarih' => self::time($c['tarih'] ?? null),
                ], $last['commitler'] ?? []),
                // Listede gösterilmeyen yeni commit sayısı ("ve N commit daha").
                'daha' => max(0, (int) ($last['geride'] ?? 0) - count($last['commitler'] ?? [])),
                // Panel güncellendiyse eski sonuç bu sürümü anlatmıyor.
                'eski' => ($last['calisan'] ?? null) !== $running,
            ],
            'durum' => $last === null ? null : self::STATUSES[$last['durum']],
            'guncellenebilir' => $this->updater->mumkun(),
            'hookYok' => ! $this->updater->hazir(),
        ]);
    }

    public function check(Request $request): RedirectResponse
    {
        $result = $this->audit->check((string) $request->user()->name);

        return back()->with($result['durum'] === 'hata' ? 'error' : 'status', match ($result['durum']) {
            'guncel' => 'Kontrol edildi: panel son sürümde.',
            'yeni' => 'Kontrol edildi: yeni sürüm var ('.($result['aciklama'] ?? '').')',
            'hata' => 'Kontrol edilemedi: '.($result['aciklama'] ?? ''),
            default => 'Kontrol edildi: '.($result['aciklama'] ?? ''),
        });
    }

    private static function time(?string $iso): ?string
    {
        try {
            return $iso === null ? null : Carbon::parse($iso)->setTimezone(config('app.timezone'))->format('d.m.Y H:i');
        } catch (Throwable) {
            return $iso;
        }
    }

    public function apply(): RedirectResponse
    {
        try {
            $sonuc = $this->updater->baslat();
        } catch (RuntimeException $e) {
            return back()->with('error', 'Güncelleme başlatılamadı: '.$e->getMessage());
        }

        $this->activity->record(null, 'guncelleme_baslat', 'Panel güncellemesi başlatıldı: '.$sonuc, ActivityChannel::Config);

        return back()->with('status', 'Güncelleme başlatıldı ('.$sonuc.'). Panel birkaç dakika içinde yeni sürümle yeniden açılır; bu sırada sayfa kısa bir süre yanıt vermeyebilir.');
    }
}
