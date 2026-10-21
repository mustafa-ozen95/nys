<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityChannel;
use App\Enums\PasswordRotationStage;
use App\Enums\PasswordRotationStatus;
use App\Http\Requests\Settings\DevicePasswordRequest;
use App\Jobs\RotateSwitchPasswordJob;
use App\Models\NetworkSwitch;
use App\Models\SwitchPasswordRotation;
use App\Services\ActivityRecorder;
use App\Services\Credentials\SwitchPasswordRotator;
use App\Services\Netconf\NetconfException;
use App\Services\Settings\SettingsAccess;
use App\Services\Settings\SettingsCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Cihaz parolalarının toplu değiştirilmesi.
 *
 * Her switche AYRI ve rastgele bir parola verilir; tek bir parolanın bütün
 * sahayı açması diye bir durum kalmaz.
 *
 * Yetkilendirme: yalnızca yönetici (SettingPolicy). Burada izin listesi
 * kullanılmaz — bu ekran panelin bütün cihazlara erişimini bir tuşla
 * değiştirir ve yanlış giderse saha yönetilemez hâle gelir. "Cihaza yaz"
 * yetkisi bunu kapsamaz.
 */
class SwitchPasswordRotationController extends Controller implements HasMiddleware
{
    public function __construct(
        private SettingsCatalog $catalog,
        private SettingsAccess $access,
        private ActivityRecorder $activity,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            // Ekrana giriş: en az bir switchte parola yetkisi.
            new Middleware('can:viewDevicePasswords', only: ['index', 'store', 'progress']),

            // Tek cihaz: o switchte parola değiştirme yetkisi.
            new Middleware('can:rotate,switch', only: ['update', 'manual']),
        ];
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        /*
         * Yalnızca kişinin parola yetkisi olduğu switchler listelenir.
         * Görebildiği ama parolasına yetkisi olmayan bir switch burada hiç
         * görünmez: ekranın tamamı parola işidir.
         */
        $switches = NetworkSwitch::query()
            ->with(['credentials', 'latestPasswordRotation'])
            ->orderBy('hostname')
            ->get()
            ->filter(fn (NetworkSwitch $switch): bool => $user->can('secret', $switch) || $user->can('rotate', $switch))
            ->values();

        return view('settings.passwords', [
            'sections' => $this->catalog->tabs('parolalar'),
            'switches' => $switches,
            'stranded' => SwitchPasswordRotation::query()
                ->stranded()
                ->with('switch')
                ->latest('id')
                ->get()
                ->filter(fn (SwitchPasswordRotation $rotation): bool => $rotation->switch !== null
                    && $user->can('rotate', $rotation->switch))
                ->values(),
            'canRotateAny' => $switches->contains(fn (NetworkSwitch $switch): bool => $user->can('rotate', $switch)),
            /*
             * Parolanın biçimi ayarlardan geliyor; ekran onu SABİT metinle
             * anlatmamalı. Ayar değiştiğinde açıklama da değişir.
             */
            'length' => (int) config('credentials.rotation.length'),
            'format' => $this->format(),
            'recoveryUsername' => (string) config('credentials.rotation.recovery_username'),

            /*
             * Üretilecek parolanın biçimi. Ayrı bir ayar sekmesi değil,
             * bu ekranın parçası: biçimi seçtiğin yer ile sonucunu gördüğün
             * yer aynı olmalı.
             *
             * Kaydetme yine ayar ucundan geçer (doğrulama, saklama ve bölüm
             * yetkisi orada); yetkisi olmayana form hiç gösterilmez.
             */
            'formatGroup' => $group = $this->catalog->group('cihaz-parolasi'),
            'formatValues' => collect($group['items'] ?? [])
                ->mapWithKeys(fn ($item): array => [$item->key => config($item->key)])
                ->all(),
            'canEditFormat' => $group !== null && $this->access->canUpdate($user, $group),

            /*
             * SÜREN turlar. İlk çizim sunucudan gelir, yoklama devralır;
             * çubuğun bir kare boş görünmesi bile "bir şey olmuyor" diye
             * okunurdu.
             */
            'active' => $active = $this->activeMap($switches),
            'ozet' => $this->activeSummary($active),
        ]);
    }

    /**
     * Süren turların toplam doluluğu: ADIM SAYISINDAN hesaplanır (biten adımlar
     * / toplam adımlar), yüzdelerin ortalamasından değil.
     *
     * @param  array<string, array<string, mixed>>  $active
     * @return array{biten: int, toplam: int, yuzde: int}
     */
    private function activeSummary(array $active): array
    {
        $biten = array_sum(array_column($active, 'adim'));
        $toplam = max(1, array_sum(array_column($active, 'toplam')));

        return ['biten' => $biten, 'toplam' => $toplam, 'yuzde' => (int) round($biten / $toplam * 100)];
    }

    /**
     * Süren turların aşaması — ekrandaki ilerleme çubuklarını besler.
     *
     * TEK İSTEK, BÜTÜN CİHAZLAR. Toplu turda onlarca switch aynı anda
     * sırada bekliyor; her satırın kendi yoklamasını yapması, sayfayı
     * saniyede onlarca isteğe boğardı.
     *
     * Uç yalnızca AŞAMA döndürür: yeni parola ve kurtarma parolası modelde
     * $hidden'dır ve buraya hiç uğramaz.
     */
    public function progress(Request $request): JsonResponse
    {
        $user = $request->user();

        $switches = NetworkSwitch::query()
            ->with(['credentials', 'latestPasswordRotation'])
            ->get()
            ->filter(fn (NetworkSwitch $switch): bool => $user->can('secret', $switch) || $user->can('rotate', $switch))
            ->values();

        $active = $this->activeMap($switches);

        return response()->json([
            'calisiyor' => $active !== [],
            'cihazlar' => $active,
        ]);
    }

    /**
     * Süren turların cihaz => aşama eşlemesi.
     *
     * Ekran ile uç AYNI kaynaktan okur; ikisi ayrı yerde hesaplansaydı biri
     * değiştiğinde diğeri sessizce eskir ve çubuk sayfa yenilenince
     * zıplardı.
     *
     * @param  Collection<int, NetworkSwitch>  $switches
     * @return array<string, array<string, mixed>>
     */
    private function activeMap($switches): array
    {
        $harita = [];

        foreach ($switches as $switch) {
            $durum = $this->durum($switch);

            if ($durum !== null) {
                $harita[$switch->uuid] = $durum;
            }
        }

        return $harita;
    }

    /** Bu cihazda süren bir tur var mı? Yoksa null. */
    private function durum(NetworkSwitch $switch): ?array
    {
        $tur = $switch->latestPasswordRotation;
        $bekleyen = RotateSwitchPasswordJob::bekleyen($switch->id);

        /*
         * Kuyruğa alındı ama işçi henüz başlamadı: tur kaydı yok, iş var.
         * Kayıt izden SONRA açıldıysa iş başlamış demektir ve iz düşer.
         */
        $baslamamis = $bekleyen !== null
            && ($tur === null || $tur->created_at?->timestamp < $bekleyen);

        if ($baslamamis) {
            return [
                'yuzde' => 4,
                'adim' => 0,
                'toplam' => PasswordRotationStage::ADIM_SAYISI,
                'metin' => 'Sıraya alındı, işçi bekleniyor…',
                'durum' => PasswordRotationStatus::Pending->value,
            ];
        }

        if ($tur === null || ! in_array($tur->status, [PasswordRotationStatus::Pending, PasswordRotationStatus::Running], true)) {
            return null;
        }

        RotateSwitchPasswordJob::iziSil($switch->id);

        $asama = $tur->stage ?? PasswordRotationStage::Started;
        $adim = $asama->step();

        return [
            'yuzde' => (int) round($adim / PasswordRotationStage::ADIM_SAYISI * 100),
            'adim' => $adim,
            'toplam' => PasswordRotationStage::ADIM_SAYISI,
            'metin' => $asama->running(),
            'durum' => $tur->status->value,
        ];
    }

    /**
     * Bütün cihazlar — SIRAYLA, biri bitmeden diğeri başlamadan.
     *
     * Her cihaz yine kendi işidir ve biri takılırsa diğerleri devam eder;
     * değişen, aynı anda kaç tanesinin koştuğu. Tur cihazda altı commit atan
     * ağır bir iştir: paralel koşunca hem işçilerin tamamı dakikalarca
     * doluyor hem de hepsi birden zamanaşımına giriyordu (bkz.
     * RotateSwitchPasswordJob::sirayaAl).
     */
    public function store(Request $request): RedirectResponse
    {
        /*
         * "Hepsi" kişinin YETKİLİ olduğu switchlerdir. Yetkisi olmayan bir
         * cihaz toplu turda da atlanır; toplu düğme yetki aşmanın yolu
         * olmamalı.
         */
        $switches = NetworkSwitch::query()
            ->whereHas('credentials')
            /*
             * Sıra EKRANDAKİ sıradır: kullanıcı listeyi yukarıdan aşağı
             * okuyup hangisinin ne zaman geleceğini kestirebilmeli.
             */
            ->orderBy('hostname')
            ->get()
            ->filter(fn (NetworkSwitch $switch): bool => $request->user()->can('rotate', $switch))
            ->values();

        RotateSwitchPasswordJob::sirayaAl(
            $switches->pluck('id')->all(),
            $request->user()?->id,
        );

        $this->activity->record(
            null,
            'configured',
            'Toplu cihaz parolası değişimi başlatıldı: '.$switches->count().' switch',
            ActivityChannel::Config,
        );

        return back()->with('status', $switches->count().' switch sıraya alındı; cihazlar tek tek işlenecek.');
    }

    /**
     * Tek cihaz. Toplu turdan farkı yok; bir switch takıldığında yalnızca
     * onu yeniden denemek için.
     */
    public function update(NetworkSwitch $switch): RedirectResponse
    {
        RotateSwitchPasswordJob::kuyrukla($switch->id, request()->user()?->id);

        return back()->with('status', "{$switch->hostname} için parola değişimi kuyruğa alındı.");
    }

    /**
     * Elle verilen parola.
     *
     * Rastgele turla BİREBİR aynı güvenli sıradan geçer; tek farkı parolanın
     * üretilmek yerine verilmiş olması. Elle seçilmiş olması onu daha az
     * tehlikeli yapmaz — yanlış giderse switch yine yönetilemez hâle gelir.
     *
     * "Yalnızca panele kaydet" diye bir seçenek YOKTUR: panelin bildiği ile
     * cihazdaki ayrı ayrı düzenlenebilseydi iki doğruluk kaynağı olurdu.
     * Panelin kaydı, panelin cihaza YAZDIĞI şeydir.
     *
     * Kuyruğa alınır: üç commit ve dört oturum sürüyor, istek boyunca
     * beklemek sayfayı dakikalarca kilitlerdi.
     */
    public function manual(DevicePasswordRequest $request, NetworkSwitch $switch): RedirectResponse
    {
        RotateSwitchPasswordJob::kuyrukla(
            $switch->id,
            $request->user()?->id,
            $request->string('password')->toString(),
        );

        return back()->with('status', "{$switch->hostname} için parola değişimi kuyruğa alındı.");
    }

    /**
     * Cihazda kalmış kurtarma hesabını kapatır.
     *
     * Kuyruğa alınmaz: kullanıcı sonucu ANINDA görmeli. Cihaza girilemiyorsa
     * ekranda "konsoldan girilmeli" yazması, kuyrukta sessizce başarısız
     * olmasından iyidir.
     */
    public function repair(SwitchPasswordRotation $rotation, SwitchPasswordRotator $rotator): RedirectResponse
    {
        abort_unless(
            $rotation->switch !== null && request()->user()->can('rotate', $rotation->switch),
            403,
        );

        try {
            $rotator->repair($rotation, request()->user());
        } catch (NetconfException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Kurtarma hesabı kapatıldı.');
    }

    /**
     * Üretilecek parolanın biçimi, insan diliyle.
     *
     * Ekran biçimi SABİT metinle anlatmamalı: uzunluk ve karakter sınıfları
     * ayarlardan geliyor ve değiştiğinde açıklama da değişmeli.
     */
    private function format(): string
    {
        $siniflar = array_keys(array_filter([
            'küçük harf' => config('credentials.rotation.lowercase'),
            'büyük harf' => config('credentials.rotation.uppercase'),
            'rakam' => config('credentials.rotation.digits'),
            'noktalama' => config('credentials.rotation.symbols'),
        ]));

        /*
         * Hiçbir sınıf açık olmayabilir: ayar ekranı bunu reddeder ama
         * config elle ya da .env üzerinden de bozulabilir. Ekran o durumda
         * boş bir cümle değil, sorunu söyleyen bir cümle basmalı.
         */
        if ($siniflar === []) {
            return 'HİÇBİR KARAKTER SINIFI AÇIK DEĞİL — parola üretilemez';
        }

        $son = array_pop($siniflar);

        return $siniflar === [] ? $son : implode(', ', $siniflar).' ve '.$son;
    }
}
