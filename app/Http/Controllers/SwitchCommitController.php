<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityChannel;
use App\Models\NetworkSwitch;
use App\Models\SwitchCommit;
use App\Services\ActivityRecorder;
use App\Services\Netconf\CommitRollback;
use App\Services\Netconf\NetconfException;
use App\Services\Switching\SwitchNavigationService;
use App\Support\Netconf\ConfigurationRedactor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Switchin commit geçmişi ve geri alma.
 *
 * Kayıtları cihaz üretir ("show system commit"), panelden girilmez;
 * eşitleme gelene kadar liste boştur.
 *
 * Geri alma cihazın TAMAMINI eski hâline döndürür — panelin yönetmediği
 * satırlar da dahil. Bu yüzden okumadan ayrı bir yetki ister ve onay
 * penceresinde switch adı yazdırılır.
 */
class SwitchCommitController extends Controller implements HasMiddleware
{
    public function __construct(
        private SwitchNavigationService $navigation,
        private CommitRollback $rollback,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:view,switch', only: ['index']),
            // Geri alma cihazın TAMAMINI etkiler (güvenlik duvarı, BGP, hesaplar dahil
            // eski bir hâli geri getirir): dar "configure" değil, tam cihaz hakkı
            // olan "provision" ister. Önizleme de aynı kapıdan geçer; fark ekranı
            // eski yapılandırmayı açar.
            new Middleware('can:provision,switch', only: ['preview', 'rollback']),
        ];
    }

    public function index(Request $request, NetworkSwitch $switch): View
    {
        $commits = $switch->commits()->orderByDesc('sequence_number')->get();

        return view('switches.commits', [
            'switch' => $switch,
            'commits' => $commits,

            // Fark penceresinin hazır verisi; Blade karar vermez.
            'preview' => $this->previewData($switch, $commits),
            'switches' => $this->navigation->switches($request->user()),
            'objects' => $this->navigation->objects($switch, 'commits'),
        ]);
    }

    /**
     * Oturumdaki fark önizlemesini ekrana hazır hâle getirir.
     *
     * @param  Collection<int, SwitchCommit>  $commits
     * @return array{label: string, diff: string, warnings: list<string>, action: string}|null
     */
    private function previewData(NetworkSwitch $switch, Collection $commits): ?array
    {
        $preview = session('rollbackPreview');

        if (! is_array($preview)) {
            return null;
        }

        $commit = $commits->firstWhere('uuid', $preview['commit'] ?? null);

        if ($commit === null) {
            return null;
        }

        return [
            'label' => $commit->committed_at?->timezone(config('app.display_timezone'))->format('d.m.Y H:i')
                ?? '#'.$commit->sequence_number,
            'diff' => (string) ($preview['diff'] ?? ''),
            'warnings' => (array) ($preview['warnings'] ?? []),
            'action' => route('switches.commits.rollback', [$switch, $commit]),
        ];
    }

    /**
     * Geri almanın ne değiştireceğini gösterir; commit atmaz.
     *
     * Geri alma cihazın tamamını etkilediği için düğme doğrudan geri almaz:
     * önce fark okunur, geri alma o farkın altından yapılır.
     */
    public function preview(NetworkSwitch $switch, SwitchCommit $commit): RedirectResponse
    {
        try {
            $sonuc = $this->rollback->preview($switch, $commit);
        } catch (NetconfException $exception) {
            /*
             * Cihaza ulaşılamaması ya da commit'in geçmişte kalmamış olması
             * bir program hatası değil, sahada olağan bir durumdur:
             * kullanıcıya ne yapacağı söylenir, 500 sayfası gösterilmez.
             */
            return back()->with('error', $exception->getMessage());
        }

        if ($sonuc['index'] === 0) {
            return back()->with('status', 'Cihaz zaten bu commit’in yapılandırmasında.');
        }

        /*
         * Fark EKRANA maskelenmiş gider.
         *
         * Cihazın kendi yapılandırması sır taşır: "encrypted-password
         * "$9$..."", "authentication-key "$9$..."", community adları. Junos'un
         * $9$ biçimi GERİ ÇEVRİLEBİLİR — düz metin kadar hassastır ve zaten
         * bu yüzden etkinlik kaydında maskeleniyordu. Önizleme o
         * maskelemeyi atlıyordu: geri alma ekranını açan herkes cihazın
         * sırlarını okuyabiliyordu.
         *
         * Panelin KENDİ sakladığı cihaz parolası ayrı bir yoldan, ayrı bir
         * yetkiyle ve kayda geçerek gösterilir (bkz. SwitchPasswordController);
         * buradaki maskeleme onu etkilemez.
         */
        return back()->with('rollbackPreview', [
            'commit' => $commit->uuid,
            'diff' => ConfigurationRedactor::apply($sonuc['diff']),
            'warnings' => $sonuc['warnings'],
        ]);
    }

    /**
     * Cihazı seçilen commit'e döndürür.
     *
     * Hedef KİMLİĞİYLE seçilir, rollback indeksi cihazdan yeniden okunur:
     * indeks her yeni commit'te kayar ve ekran açıkken başkası commit
     * atmışsa yanlış yapılandırma yüklenirdi (bkz. CommitRollback).
     */
    public function rollback(NetworkSwitch $switch, SwitchCommit $commit): RedirectResponse
    {
        try {
            $sonuc = $this->rollback->apply($switch, $commit);
        } catch (NetconfException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if (! $sonuc['committed']) {
            return back()->with('status', 'Cihaz zaten bu commit’in yapılandırmasında; değişiklik yapılmadı.');
        }

        $zaman = $commit->committed_at?->timezone(config('app.display_timezone'))->format('d.m.Y H:i') ?? '—';

        $this->activity->record(
            $switch,
            'configured',
            "{$switch->hostname} · {$zaman} commit’ine geri alındı: "
                .str_replace("\n", ' ', ConfigurationRedactor::apply($sonuc['diff'])),
            ActivityChannel::Config,
        );

        return back()->with('status', "{$switch->hostname} cihazı {$zaman} commit’ine döndürüldü.");
    }
}
