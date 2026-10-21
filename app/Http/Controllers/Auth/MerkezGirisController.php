<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\ActivityChannel;
use App\Http\Controllers\Controller;
use App\Services\ActivityRecorder;
use App\Services\Kimlik\HesapAcici;
use App\Services\Kimlik\HesapEslestirici;
use App\Services\Kimlik\MerkezIstemcisi;
use App\Services\Kimlik\MerkezYetkisizException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Kimlik Yönetim Merkezi ile giriş.
 *
 * PAROLA BU PANELE HİÇ UĞRAMAZ: kullanıcı merkezin kendi sayfasında giriş
 * yapar, buraya tek kullanımlık bir kod döner. Bu yüzden düğme TAM SAYFA
 * yönlendirir; bir pencere içinde sorulsaydı kullanıcı parolasını yazarken
 * adres çubuğunda merkezi göremezdi.
 *
 * BU AKIŞ PANELİN KENDİ GİRİŞİNİ DEĞİŞTİRMEZ. Parolayla giriş yerinde kalır;
 * merkez erişilemez olduğunda panele girilebilmeli.
 *
 * Yetkilendirme: iki uç da `guest` altındadır — oturumu olan birinin yeniden
 * giriş akışına girmesi anlamsızdır. Kapalı kurulumda uçlar 404 döner.
 */
class MerkezGirisController extends Controller
{
    private const DURUM = 'kimlik_durum';

    private const DOGRULAYICI = 'kimlik_dogrulayici';

    public function baslat(Request $request, MerkezIstemcisi $merkez): RedirectResponse
    {
        abort_unless($merkez->acikMi(), 404);

        $dogrulayici = $merkez->dogrulayici();
        $durum = Str::random(40);

        /* İkisi de OTURUMDA kalır: geri dönen isteğin bizim başlattığımız akış olduğunu böyle biliriz. */
        $request->session()->put(self::DOGRULAYICI, $dogrulayici);
        $request->session()->put(self::DURUM, $durum);

        return redirect()->away($merkez->girisAdresi($durum, $merkez->meydanOkuma($dogrulayici)));
    }

    public function geri(
        Request $request,
        MerkezIstemcisi $merkez,
        HesapEslestirici $eslestirici,
        HesapAcici $acici,
        ActivityRecorder $recorder,
    ): RedirectResponse {
        abort_unless($merkez->acikMi(), 404);

        $beklenen = $request->session()->pull(self::DURUM);
        $dogrulayici = $request->session()->pull(self::DOGRULAYICI);

        /*
         * DURUM EŞLEŞMELİ (CSRF).
         *
         * Eşleşmiyorsa bu akışı biz başlatmamışız demektir: saldırgan, kendi
         * kodunu kurbanın tarayıcısında kullandırıp onu kendi hesabına
         * bağlayabilirdi.
         */
        if (blank($beklenen) || ! hash_equals((string) $beklenen, (string) $request->query('state'))) {
            return $this->hata('Giriş akışı doğrulanamadı; lütfen yeniden deneyin.');
        }

        if (blank($request->query('code')) || blank($dogrulayici)) {
            return $this->hata('Merkezden kod gelmedi.');
        }

        try {
            $jeton = $merkez->jeton((string) $request->query('code'), (string) $dogrulayici);
            $kimlik = $merkez->kullanici((string) ($jeton['access_token'] ?? ''));
        } catch (MerkezYetkisizException $e) {
            /*
             * MERKEZ KAPIYI KAPATTI — hata değil, KARAR.
             *
             * Hangi rolün bu panele girebileceğine merkez karar veriyor
             * (İstemciler ekranındaki rol listesi). Bağlantı hatasından ayrı
             * karşılanır: orada kullanıcı parolasıyla girişe düşer, burada
             * kapı kapalıdır ve parola da açmaz. Red kayda geçer: kimin
             * nereye girmeye çalıştığı sorulacak.
             */
            $recorder->recordAs(null, null, $request->ip(), null, 'login_failed',
                'Merkez erişimi reddetti: '.$e->getMessage(), ActivityChannel::Auth);

            return $this->hata($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->hata('Merkezle bağlantı kurulamadı; lütfen parolanızla girin.');
        }

        $kullanici = $eslestirici->bul($kimlik);

        /*
         * Eşleşme yoksa: hesap ya AÇILIR ya da kişi geri çevrilir.
         *
         * Kararı ayar verir (bkz. HesapAcici). Kapalıyken merkezde hesabı
         * olmak bu panelde işi olmak demek değildir ve hesabı yönetici açar.
         * Açıkken hesap merkezdeki rolün yerel karşılığıyla doğar.
         *
         * KOŞULLAR MERKEZDE: hesap orada onaylanmış (adresi doğrulanmış
         * sayılıyor) ve kişiye BİR ROL atanmış olmalı. Rolsüz hesap içeri
         * alınmaz — rol atamak, o kişiye bakılmış olmasının kendisidir
         * (bkz. HesapAcici::acilabilirMi).
         */
        if ($kullanici === null && $acici->acilabilirMi($kimlik)) {
            $kullanici = $acici->ac($kimlik);

            $recorder->recordAs($kullanici->id, null, $request->ip(), $kullanici, 'kimlik_hesap_acildi',
                $acici->aciklama($kullanici, $kimlik), ActivityChannel::Auth);
        }

        if ($kullanici === null) {
            $recorder->recordAs(null, null, $request->ip(), null, 'login_failed',
                "Merkez kimliği eşleşmedi: {$kimlik['email']}", ActivityChannel::Auth);

            return $this->hata('Bu kimlikle eşleşen bir hesap yok. Hesap açma isteği için sistem yöneticinize başvurun.');
        }

        if (! $kullanici->isActive()) {
            return $this->hata('Hesabınız etkin değil. Sistem yöneticinize başvurun.');
        }

        $ilkBag = $eslestirici->bagla($kullanici, $kimlik);

        /*
         * Yalnızca İLK bağ kaydedilir.
         *
         * Girişin kendisini `RecordAuthenticationActivity` zaten yazıyor;
         * burada tekrarlamak denetim kaydını ikizlerdi. Asıl anlatılmaya değer
         * olay, merkezdeki bir kimliğin bu hesaba ilk kez bağlanmasıdır.
         */
        if ($ilkBag->wasRecentlyCreated) {
            $recorder->recordAs($kullanici->id, null, $request->ip(), $kullanici, 'kimlik_baglandi',
                "Merkez kimliği hesaba bağlandı: {$kimlik['email']}", ActivityChannel::Auth);
        }

        /*
         * "Beni hatırla" ÇEREZİ VERİLMEZ.
         *
         * Oturum boşta kalma süresi dolunca biter (bkz. config/session.php);
         * remember=true 400 günlük bir çerez bırakıp bu süreyi geçersiz
         * kılardı: süre dolsa da çerezle oturum yeniden açılırdı. Parola
         * girişi de remember kullanmaz; iki yol aynı kurala bağlıdır.
         */
        Auth::login($kullanici, false);
        $request->session()->regenerate();

        return redirect()->intended(route('overview'));
    }

    private function hata(string $mesaj): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $mesaj]);
    }
}
