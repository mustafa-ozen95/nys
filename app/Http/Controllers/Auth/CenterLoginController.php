<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\ActivityChannel;
use App\Http\Controllers\Controller;
use App\Services\ActivityRecorder;
use App\Services\Identity\AccountMatcher;
use App\Services\Identity\AccountOpener;
use App\Services\Identity\CenterClient;
use App\Services\Identity\CenterUnauthorizedException;
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
class CenterLoginController extends Controller
{
    private const STATUS = 'kimlik_durum';

    private const VERIFIER = 'kimlik_dogrulayici';

    public function start(Request $request, CenterClient $center): RedirectResponse
    {
        abort_unless($center->isOpen(), 404);

        $verifier = $center->verifier();
        $status = Str::random(40);

        /* İkisi de OTURUMDA kalır: geri dönen isteğin bizim başlattığımız akış olduğunu böyle biliriz. */
        $request->session()->put(self::VERIFIER, $verifier);
        $request->session()->put(self::STATUS, $status);

        return redirect()->away($center->loginAddress($status, $center->challengeRead($verifier)));
    }

    public function back(
        Request $request,
        CenterClient $center,
        AccountMatcher $matcher,
        AccountOpener $opener,
        ActivityRecorder $recorder,
    ): RedirectResponse {
        abort_unless($center->isOpen(), 404);

        $expected = $request->session()->pull(self::STATUS);
        $verifier = $request->session()->pull(self::VERIFIER);

        /*
         * DURUM EŞLEŞMELİ (CSRF).
         *
         * Eşleşmiyorsa bu akışı biz başlatmamışız demektir: saldırgan, kendi
         * kodunu kurbanın tarayıcısında kullandırıp onu kendi hesabına
         * bağlayabilirdi.
         */
        if (blank($expected) || ! hash_equals((string) $expected, (string) $request->query('state'))) {
            return $this->error('Giriş akışı doğrulanamadı; lütfen yeniden deneyin.');
        }

        if (blank($request->query('code')) || blank($verifier)) {
            return $this->error('Merkezden kod gelmedi.');
        }

        try {
            $token = $center->token((string) $request->query('code'), (string) $verifier);
            $identity = $center->user((string) ($token['access_token'] ?? ''));
        } catch (CenterUnauthorizedException $e) {
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

            return $this->error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->error('Merkezle bağlantı kurulamadı; lütfen parolanızla girin.');
        }

        $user = $matcher->find($identity);

        /*
         * Eşleşme yoksa: hesap ya AÇILIR ya da kişi geri çevrilir.
         *
         * Kararı ayar verir (bkz. AccountOpener). Kapalıyken merkezde hesabı
         * olmak bu panelde işi olmak demek değildir ve hesabı yönetici açar.
         * Açıkken hesap merkezdeki rolün yerel karşılığıyla doğar.
         *
         * KOŞULLAR MERKEZDE: hesap orada onaylanmış (adresi doğrulanmış
         * sayılıyor) ve kişiye BİR ROL atanmış olmalı. Rolsüz hesap içeri
         * alınmaz — rol atamak, o kişiye bakılmış olmasının kendisidir
         * (bkz. AccountOpener::acilabilirMi).
         */
        if ($user === null && $opener->canOpen($identity)) {
            $user = $opener->open($identity);

            $recorder->recordAs($user->id, null, $request->ip(), $user, 'kimlik_hesap_acildi',
                $opener->description($user, $identity), ActivityChannel::Auth);
        }

        if ($user === null) {
            $recorder->recordAs(null, null, $request->ip(), null, 'login_failed',
                "Merkez kimliği eşleşmedi: {$identity['email']}", ActivityChannel::Auth);

            return $this->error('Bu kimlikle eşleşen bir hesap yok. Hesap açma isteği için sistem yöneticinize başvurun.');
        }

        if (! $user->isActive()) {
            return $this->error('Hesabınız etkin değil. Sistem yöneticinize başvurun.');
        }

        $firstBag = $matcher->bind($user, $identity);

        /*
         * Yalnızca İLK bağ kaydedilir.
         *
         * Girişin kendisini `RecordAuthenticationActivity` zaten yazıyor;
         * burada tekrarlamak denetim kaydını ikizlerdi. Asıl anlatılmaya değer
         * olay, merkezdeki bir kimliğin bu hesaba ilk kez bağlanmasıdır.
         */
        if ($firstBag->wasRecentlyCreated) {
            $recorder->recordAs($user->id, null, $request->ip(), $user, 'kimlik_baglandi',
                "Merkez kimliği hesaba bağlandı: {$identity['email']}", ActivityChannel::Auth);
        }

        /*
         * "Beni hatırla" ÇEREZİ VERİLMEZ.
         *
         * Oturum boşta kalma süresi dolunca biter (bkz. config/session.php);
         * remember=true 400 günlük bir çerez bırakıp bu süreyi geçersiz
         * kılardı: süre dolsa da çerezle oturum yeniden açılırdı. Parola
         * girişi de remember kullanmaz; iki yol aynı kurala bağlıdır.
         */
        Auth::login($user, false);
        $request->session()->regenerate();

        return redirect()->intended(route('overview'));
    }

    private function error(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
