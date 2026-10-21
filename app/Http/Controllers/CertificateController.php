<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Certificate\CertificateType;
use App\Services\Settings\SettingsCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * TLS sertifikası — durum, doğrulama kayıtları ve tur.
 *
 * SERTİFİKA AYNI VEKİLİN ARKASINDAKİ PANELLERİ BİRLİKTE KAPSAYABİLİR ve
 * ortak vekilde durur; tur hangi panelden başlatılırsa başlatılsın aynı işi
 * yapar.
 *
 * PANEL SERTİFİKA ALMAZ. ACME hesabı, özel anahtar ve sertifika dosyaları
 * sunucuda durur; panel yalnızca içinde eylemin adı olan küçük bir istek
 * dosyası bırakır (bkz. kurulum tarafındaki sertifika ajanı). Ele geçirilen
 * bir panel, alan adları için geçerli sertifika üretememeli.
 */
class CertificateController extends Controller implements HasMiddleware
{
    public function __construct(
        private SettingsCatalog $catalog,
        private CertificateType $sertifika,
    ) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:manageSertifika')];
    }

    public function index(): View
    {
        return view('settings.certificate', [
            'sections' => $this->catalog->tabs('sertifika'),
            'durum' => $this->sertifika->durum(),
            'kayitlar' => $this->sertifika->kayitlar(),
            'islem' => $this->sertifika->sonIslem(),
            'yayilma' => $this->sertifika->yayilmaDurumu(),
            'bekliyor' => $this->sertifika->istekBekliyorMu(),
        ]);
    }

    /** Yeni tur: sunucu ACME siparişini açar ve TXT kayıtlarını yazar. */
    public function baslat(): RedirectResponse
    {
        if (! $this->sertifika->istekBirak('baslat')) {
            return $this->iletilemedi();
        }

        return back()->with('status', 'Tur başlatıldı. Kayıtlar birkaç saniye içinde burada görünecek.');
    }

    /**
     * Onayla: kayıtların YAYILDIĞINI panel kendi sorar, sonra turu tamamlatır.
     *
     * Yayılmamış bir kayıtla sağlayıcıya gitmek başarısız bir deneme demek;
     * tur baştan başlar ve kullanıcı sebebini anlamaz.
     */
    public function tamamla(): RedirectResponse
    {
        $eksik = collect($this->sertifika->yayilmaDurumu())
            ->reject(fn (array $k): bool => $k['yayildi'])
            ->pluck('ad')
            ->all();

        if ($eksik !== []) {
            return back()->withErrors([
                'sertifika' => 'Şu kayıtlar DNS\'te henüz görünmüyor: '.implode(', ', $eksik),
            ]);
        }

        if (! $this->sertifika->istekBirak('tamamla')) {
            return $this->iletilemedi();
        }

        return back()->with('status', 'Sertifika alınıyor. Bu işlem bir dakikayı bulabilir.');
    }

    /**
     * İstek dosyası yazılamadı: sunucudaki ajan hiçbir şey görmez. Yine de
     * "tur başlatıldı" demek kullanıcıyı boşuna bekletir; istek dizininin
     * sahibi yanlışsa (ör. root) sekme tam olarak böyle sessizce hiçbir şey
     * yapmazdı.
     */
    private function iletilemedi(): RedirectResponse
    {
        return back()->withErrors([
            'sertifika' => 'İstek sunucuya iletilemedi: panel istek dizinine (/etc/dc/sertifika-panel) yazamıyor. Sunucu yöneticisine bildirin.',
        ]);
    }
}
