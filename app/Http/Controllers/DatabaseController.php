<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityChannel;
use App\Services\ActivityRecorder;
use App\Services\Database\ConnectionFile;
use App\Services\Database\RemoteDatabase;
use App\Services\Settings\SettingsCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;
use Throwable;

/**
 * Panelin veritabanı — nerede olduğu ve uzak bir sunucuya taşınması.
 *
 * İKİ ADIM: önce SINA, sonra TAŞI. Sınanan bağlantı oturumda şifreli durur;
 * taşı düğmesi parolayı yeniden istemez ama sınamaları yeniden yapar —
 * arada hedef dolmuş ya da yetki alınmış olabilir.
 *
 * Taşımanın kendisi panel kapalıyken başlatıcıda yapılır (bkz. ConnectionFile).
 * Bu ekran isteği bırakır ve konteyneri yeniden başlatır.
 *
 * İKİ İŞLEM: TAŞI şu anki veritabanını boş bir hedefe kopyalar. BAĞLAN
 * kopyalamaz; yeni bir kurulumu eski kurulumun dolu veritabanına geçirir.
 * Bağlanırken eski kurulumun APP_KEY ve AUDIT_KEY değerleri bu kurulumda da
 * olmalı (şifreli alanlar ve denetim zinciri onlara bağlı); yönetici bunu
 * onaylar, panel bunu hedefte sınayamaz.
 *
 * Yetkilendirme: HasMiddleware ile `can:manageDatabase` (yalnızca yönetici).
 */
class DatabaseController extends Controller implements HasMiddleware
{
    /** Sınanan bağlantı bu kadar süre taşınabilir; sonra yeniden sınanır. */
    private const TEST_LIFETIME = 900;

    private const SESSION_KEY = 'database.tested';

    /** Süren ya da tamamlanmış bir işlem varken yenisi başlatılmaz. */
    private const RUNNING = ['tasinacak', 'baglanacak', 'tasiniyor', 'deneniyor', 'etkin'];

    private const OPERATIONS = ['tasi' => 'Taşı: şu anki veritabanını boş hedefe kopyala', 'baglan' => 'Bağlan: hedefteki mevcut veriyle aç (kopyalamaz)'];

    private const SOURCES = [
        'ortam' => ['etiket' => 'Kurulum ayarı', 'aciklama' => 'Bağlantı kurulumun ortam değişkenlerinden (DB_*) geliyor; varsayılanı compose\'taki db servisi.'],
        'panel' => ['etiket' => 'Uzak (panel ayarı)', 'aciklama' => 'Bu sekmeden taşındı ya da bağlandı; bağlantı panelin depolama hacminde şifreli duruyor ve kurulum ayarını eziyor.'],
    ];

    /** @var array<string, array{etiket: string, renk: string}> */
    private const STAGES = [
        'tasinacak' => ['etiket' => 'istendi, yeniden başlatma bekleniyor', 'renk' => 'blue'],
        'baglanacak' => ['etiket' => 'bağlanma istendi, yeniden başlatma bekleniyor', 'renk' => 'blue'],
        'tasiniyor' => ['etiket' => 'kopyalanıyor', 'renk' => 'blue'],
        'deneniyor' => ['etiket' => 'uzak veritabanıyla açılıyor', 'renk' => 'blue'],
        'etkin' => ['etiket' => 'tamamlandı', 'renk' => 'green'],
        'basarisiz' => ['etiket' => 'yapılmadı', 'renk' => 'red'],
    ];

    public function __construct(
        private SettingsCatalog $catalog,
        private RemoteDatabase $database,
        private ConnectionFile $file,
        private ActivityRecorder $activity,
    ) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:manageDatabase')];
    }

    public function index(Request $request): View
    {
        $tested = $this->tested($request);
        $status = $this->file->status();
        $info = $this->database->info();

        return view('settings.database', [
            'sections' => $this->catalog->tabs('database'),
            'bilgi' => $info,
            'kaynak' => self::SOURCES[$info['kaynak']],
            'adres' => $info['username'].'@'.$info['host'].':'.$info['port'].'/'.$info['database'],
            'durum' => $status === null ? null : [
                ...$status,
                'istenme' => self::time($status['istenme'] ?? null),
                'zaman' => self::time($status['zaman'] ?? null),
            ],
            'asama' => $status === null ? null : self::STAGES[$status['durum']],
            // Süren ya da biten bir taşıma varken yeni taşıma formu gösterilmez.
            'tasinabilir' => $info['kaynak'] === 'ortam'
                && ! in_array($status['durum'] ?? null, self::RUNNING, true),
            'islemler' => self::OPERATIONS,
            'sonuclar' => session('veritabani.sonuclar'),
            'sinanan' => $tested === null ? null : [
                ...array_diff_key($tested, ['password' => true]),
                'adres' => $tested['username'].'@'.$tested['host'].':'.$tested['port'].'/'.$tested['database'],
            ],
        ]);
    }

    public function sina(Request $request): RedirectResponse
    {
        $target = ConnectionFile::clean($request->validate([
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'username' => ['required', 'string', 'max:80'],
            'password' => ['required', 'string', 'max:200'],
            'islem' => ['nullable', 'in:tasi,baglan'],
            'anahtar_onay' => ['accepted_if:islem,baglan'],
        ], [
            'anahtar_onay.accepted_if' => 'Bağlanmak için eski kurulumun APP_KEY ve AUDIT_KEY değerlerini bu kuruluma verin ve «Eski kurulumun anahtarlarını bu kuruluma da verdim» kutusunu işaretleyin.',
            'host.regex' => 'Konak adı ya da IPv4 adresi girin.',
            'database.regex' => 'Veritabanı adında yalnızca harf, rakam, alt çizgi ve tire olabilir.',
        ]));

        if (RemoteDatabase::isInner($target['host'])) {
            return back()->withInput($request->except('password'))
                ->withErrors(['host' => 'Bu adres konteynerin kendisi; uzak sunucunun adresini girin.']);
        }

        $operation = $request->input('islem') === 'baglan' ? 'baglan' : 'tasi';
        $results = $this->database->sina($target, $operation);
        $done = RemoteDatabase::allDone($results);

        if ($done) {
            $request->session()->put(self::SESSION_KEY, Crypt::encrypt([...$target, 'islem' => $operation, 'zaman' => time()]));
        } else {
            $request->session()->forget(self::SESSION_KEY);
        }

        return back()
            ->withInput($request->except('password'))
            ->with('veritabani.sonuclar', $results)
            ->with($done ? 'status' : 'error', match (true) {
                $done && $operation === 'baglan' => 'Sınamalar geçti. Bağlanmak için aşağıdaki düğmeyi kullanın.',
                $done => 'Sınamalar geçti. Taşımak için aşağıdaki düğmeyi kullanın.',
                $operation === 'baglan' => 'Hedefe bağlanılamaz; ayrıntılar aşağıda.',
                default => 'Hedef taşımaya uygun değil; ayrıntılar aşağıda.',
            });
    }

    public function migrate(Request $request): RedirectResponse
    {
        $target = $this->tested($request);

        if ($target === null) {
            return back()->withErrors(['veritabani' => 'Önce bağlantıyı sınayın (sınama 15 dakika geçerli).']);
        }

        if (in_array($this->file->stage(), self::RUNNING, true)) {
            return back()->withErrors(['veritabani' => 'Süren ya da tamamlanmış bir taşıma var.']);
        }

        $operation = $target['islem'];
        $results = $this->database->sina(ConnectionFile::clean($target), $operation);

        if (! RemoteDatabase::allDone($results)) {
            $request->session()->forget(self::SESSION_KEY);

            return back()
                ->with('veritabani.sonuclar', $results)
                ->withErrors(['veritabani' => 'Sınamalar artık geçmiyor; '.($operation === 'baglan' ? 'bağlanılmadı.' : 'taşıma başlatılmadı.')]);
        }

        $address = $target['username'].'@'.$target['host'].':'.$target['port'].'/'.$target['database'];

        try {
            $this->file->connectionWrite($target);
            $this->file->stageWrite($operation === 'baglan' ? 'baglanacak' : 'tasinacak', null, [
                'hedef' => $address,
                'islem' => $operation,
                'isteyen' => $request->user()?->name,
                'istenme' => date('c'),
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->file->connectionDelete();

            return back()->withErrors(['veritabani' => 'Taşıma isteği yazılamadı (storage/app/database). Sunucu yöneticisine bildirin.']);
        }

        // Gerekçeyi (onay penceresinden gelen "reason") kaydedici kendisi alır.
        $this->activity->record(null, $operation === 'baglan' ? 'veritabani_baglan' : 'veritabani_tasi',
            ($operation === 'baglan' ? 'Mevcut veritabanına bağlanma istendi: ' : 'Veritabanı taşıması istendi: ').$address, ActivityChannel::Crud);
        $request->session()->forget(self::SESSION_KEY);

        if (! $this->file->reStartRequest()) {
            return back()->withErrors(['veritabani' => 'Taşıma istendi ama panel yeniden başlatılamadı. Konteyneri elle yeniden başlatın; taşıma açılışta yapılır.']);
        }

        return back()->with('status', $operation === 'baglan'
            ? 'Bağlanma istendi. Panel birkaç saniye içinde yeniden başlayacak ve hedef veritabanıyla açılacak; oturumlar kapanır. Sonucu bu sekmede görürsünüz.'
            : 'Taşıma istendi. Panel birkaç saniye içinde yeniden başlayacak ve kopya bitene kadar erişilemeyecek; oturumlar kapanır. Sonucu bu sekmede görürsünüz.');
    }

    /** Dosyadaki ISO zaman, panelin saat diliminde okunur biçime. */
    private static function time(?string $iso): ?string
    {
        try {
            return $iso === null ? null : Carbon::parse($iso)->setTimezone(config('app.timezone'))->format('d.m.Y H:i');
        } catch (Throwable) {
            return $iso;
        }
    }

    /** @return array{host: string, port: int, database: string, username: string, password: string, islem: 'tasi'|'baglan'}|null */
    private function tested(Request $request): ?array
    {
        $encrypted = $request->session()->get(self::SESSION_KEY);

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            $veri = (array) Crypt::decrypt($encrypted);
        } catch (Throwable) {
            return null;
        }

        return time() - (int) ($veri['zaman'] ?? 0) <= self::TEST_LIFETIME
            ? [...ConnectionFile::clean($veri), 'islem' => ($veri['islem'] ?? 'tasi') === 'baglan' ? 'baglan' : 'tasi']
            : null;
    }
}
