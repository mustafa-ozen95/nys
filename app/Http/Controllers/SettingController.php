<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityChannel;
use App\Http\Requests\Settings\SettingsRequest;
use App\Models\Setting;
use App\Services\ActivityRecorder;
use App\Services\Settings\SettingDefinition;
use App\Services\Settings\SettingsAccess;
use App\Services\Settings\SettingsCatalog;
use App\Services\Settings\SettingsRepository;
use App\Services\Settings\SystemHealthService;
use App\Services\Zabbix\ZabbixClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Çalışma zamanı ayarlarının yönetimi.
 *
 * Her bölüm kendi sayfasıdır (/ayarlar/izleme, /ayarlar/cihaz...); giriş
 * sayfası durum özetidir. Kırk ayarın tek sayfada durması karışıklık
 * üretiyordu.
 *
 * Yalnızca yönetici. Bu ekran ölçüm kaynağını, cihaz erişim süresini ve geri
 * alınabilir commit penceresini değiştirir; yani panelin güvenlik ağını.
 *
 * Gizli alanlar (API jetonu) hiçbir zaman ekrana basılmaz ve boş gönderim
 * mevcut değeri korur.
 */
class SettingController extends Controller implements HasMiddleware
{
    public function __construct(
        private SettingsCatalog $catalog,
        private SettingsRepository $settings,
        private SettingsAccess $access,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.Setting::class, only: ['index', 'show']),
            new Middleware('can:update,'.Setting::class, only: ['update', 'test']),
        ];
    }

    /**
     * Giriş sayfası: hangi kaynak konuşuyor, hangisi kesik, kuyrukta ne var.
     */
    public function index(SystemHealthService $health): View
    {
        return view('settings.index', [
            'sections' => $this->catalog->tabs(),
            'health' => $health->snapshot(),
        ]);
    }

    public function show(Request $request, string $section): View
    {
        $group = $this->group($section);

        abort_unless($this->access->canView($request->user(), $group), 403);

        return view('settings.show', [
            'sections' => $this->catalog->tabs($section),
            'group' => $group,
            'values' => $this->currentValues($group),
            'audit' => $this->auditFor($group),
        ]);
    }

    public function update(SettingsRequest $request, string $section): RedirectResponse
    {
        $group = $this->group($section);

        /*
         * Bölüm bölüm sorulur: bir bölüme yetkisi olan diğerine dokunamaz.
         * İsteğin gövdesi de bölümle sınırlanır (bkz. SettingsRequest);
         * başka bölümün alanını bu forma iliştirmek işe yaramaz.
         */
        abort_unless($this->access->canUpdate($request->user(), $group), 403);

        $changed = $this->settings->save($request->settings($this->catalog), $request->user());

        if ($changed === []) {
            return back()->with('status', 'Değişiklik yok.');
        }

        $this->activity->record(
            null,
            'updated',
            "Ayar değiştirildi ({$group['label']}): ".implode(', ', $changed),
            ActivityChannel::Crud,
        );

        return back()->with('status', count($changed).' ayar kaydedildi.');
    }

    /**
     * İzleme sistemine tek bir okuma isteği gönderir.
     *
     * Kullanıcının tetiklediği, yalnızca okuyan bir çağrıdır: adresi ve jetonu
     * kaydettikten sonra "çalışıyor mu" sorusunun ekranda cevabı olmalı.
     * Zabbix'e hiçbir şey yazılmaz.
     */
    public function test(ZabbixClient $client): RedirectResponse
    {
        if (! $client->isConfigured()) {
            return back()->with('error', 'İzleme sistemi adresi ya da jetonu tanımlı değil.');
        }

        try {
            $hosts = $client->call('host.get', ['output' => ['hostid'], 'limit' => 1]);
        } catch (ConnectionException $exception) {
            return back()->with('error', 'İzleme sistemine ulaşılamadı: '.$exception->getMessage());
        } catch (Throwable $exception) {
            return back()->with('error', 'İzleme sistemi hata döndürdü: '.$exception->getMessage());
        }

        return back()->with('status', 'İzleme sistemine bağlanıldı; '.count($hosts).' host okundu.');
    }

    /**
     * @return array{key: string, slug: string, label: string, description: string, items: list<SettingDefinition>}
     */
    private function group(string $section): array
    {
        return $this->catalog->group($section)
            ?? throw new NotFoundHttpException("Böyle bir ayar bölümü yok: {$section}");
    }

    /**
     * Bu bölümde varsayılandan SAPMIŞ ayarlar: hangisi, kim, ne zaman.
     *
     * Dokunulmamış ayar listede yoktur — hepsini saymak "neyin elle
     * değiştirildiği" sorusunu gölgeler.
     *
     * @param  array{items: list<SettingDefinition>}  $group
     * @return list<array{label: string, by: ?string, at: ?string}>
     */
    private function auditFor(array $group): array
    {
        $trail = $this->settings->auditTrail();
        $rows = [];

        foreach ($group['items'] as $definition) {
            if (isset($trail[$definition->key])) {
                $rows[] = [
                    'label' => $definition->label,
                    'by' => $trail[$definition->key]['by'],
                    'at' => $trail[$definition->key]['at']?->diffForHumans(),
                ];
            }
        }

        return $rows;
    }

    /**
     * Formda gösterilecek değerler: kayıtlı ayar yoksa config dosyasındaki
     * varsayılan. Gizli alanlar hiç doldurulmaz.
     *
     * @param  array{items: list<SettingDefinition>}  $group
     * @return array<string, mixed>
     */
    private function currentValues(array $group): array
    {
        $values = [];

        foreach ($group['items'] as $definition) {
            $values[$definition->key] = $definition->isSecret() ? null : config($definition->key);
        }

        return $values;
    }
}
