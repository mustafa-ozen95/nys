<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Durumu değiştiren her işlem GEREKÇE ister.
 *
 * Denetimde sorulan soru "kim ne yaptı" kadar "neden yaptı"dır. Gerekçe
 * kullanıcıdan onay penceresinde alınır, etkinlik kaydına yazılır ve hash
 * zincirine girdiği için sonradan değiştirilemez.
 *
 * Kontrol sunucudadır: pencereyi atlayıp doğrudan istek göndermek işe
 * yaramaz.
 */
class RequireActionReason
{
    public const FIELD = 'reason';

    /** En az bu kadar karakter; "ok" gibi boş geçiştirmeler kabul edilmez. */
    private const MINIMUM = 3;

    /**
     * Gerekçe istenmeyen uçlar.
     *
     * Oturum kapatma kullanıcının kendi hesabıyla ilgilidir, bir kaydı
     * değiştirmez. Önizleme ve bağlantı sınama yalnızca OKUR. Uzak oturum
     * uçları kendi kayıtlarını ayrıca tutar ve terminal her açılışında
     * gerekçe istemek kullanılamaz hâle getirirdi.
     *
     * Cihaz parolasını gösterme ucu bir şeyi DEĞİŞTİRMEZ; POST olmasının
     * tek sebebi sırrın adres çubuğuna, tarayıcı geçmişine ve ön yüklemeye
     * takılmamasıdır. Gerekçe penceresi tek tıkla görmeyi imkânsız kılardı;
     * denetim izini uç zaten kendisi yazıyor (kim, ne zaman, hangi switch).
     *
     * Ayrıntı düzeyi (basit/gelişmiş) kullanıcının KENDİ görünüm tercihidir:
     * hiçbir kaydı ve hiçbir cihazı değiştirmez. Gerekçe istenmesi düğmeyi
     * sessizce çalışmaz hâle getiriyordu — istek doğrulama hatasıyla geri
     * dönüyor, kullanıcı yalnızca "bir şey olmadı" görüyordu.
     *
     * Bildirimleri okundu işaretlemek de AYNI TÜRDEN bir tercihtir: yalnızca
     * o kullanıcının sayacını düşürür, hiçbir kaydı ve hiçbir cihazı
     * değiştirmez. Gerekçe istenseydi "tümünü okundu işaretle" ve "tümü"
     * bağlantısı 422 alır, kullanıcı hiçbir şey göremezdi.
     *
     * Giriş ve parola sıfırlama burada YOKTUR: onlar oturum açılmadan
     * yapıldığı için yukarıdaki kimlik kontrolüyle zaten geçiliyor.
     *
     * @var list<string>
     */
    private const IGNORED = [
        'logout',
        'panel-mode.toggle',
        'notifications.seen',

        /*
         * Geri alma FARKINI okur, commit ATMAZ. Geri almanın kendisi gerekçe
         * istemeye devam eder; farkı görmek için gerekçe sormak düğmeyi
         * sessizce çalışmaz hâle getiriyordu.
         */
        'switches.commits.preview',
        'settings.test',

        /*
         * Uzak veritabanı bağlantısını SINAR, taşımaz: panelde hiçbir şey
         * değişmez (hedefteki sınama tabloları kaldırılır). Taşımanın kendisi
         * (settings.veritabani.tasi) gerekçe istemeye devam eder.
         */
        'settings.veritabani.sina',

        /*
         * Güncelleme kontrolü GitHub'a BAKAR, panelde hiçbir şey değiştirmez;
         * sonucu yazar. Her sabah kendiliğinden de yapılıyor.
         */
        'settings.guncelleme.denetle',
        'announcements.preview',
        'ajax.remote.connect',
        'ajax.remote.keep-alive',
        'ajax.remote.disconnect',
        'switches.password',

        /*
         * KENDİ hesabını düzenlemek.
         *
         * Gerekçe, başkasını etkileyen bir işi yaptığında anlamlıdır: bir
         * cihaza yazmak, birinin yetkisini değiştirmek. Kendi adını ya da
         * parolasını değiştiren kişiden gerekçe istemek, onu anlamsız bir
         * metin yazmaya zorlar — ve gerçekten gerekçe istenen yerlerde de
         * "..." yazma alışkanlığı doğurur.
         *
         * Denetim kaydı yine tutuluyor: kim, ne zaman, nereden.
         */
        'hesap.profil.kaydet',
        'hesap.parola',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Kimliği belirsiz istekten gerekçe istenmez: giriş ve parola
         * sıfırlama oturum açılmadan yapılır ve bir kaydı değiştirmez.
         *
         * Bu kontrol rota adına GÜVENMEZ. Giriş POST'unun adı yoktur
         * (adlandırılan yalnızca GET olan "login"dir) ve yalnızca ada
         * bakıldığında giriş ekranı gerekçe isteyerek kilitleniyordu.
         */
        if ($request->user() === null) {
            return $next($request);
        }

        if (! $this->changesState($request) || $this->isIgnored($request)) {
            return $next($request);
        }

        $reason = trim((string) $request->input(self::FIELD));

        if (mb_strlen($reason) < self::MINIMUM) {
            throw ValidationException::withMessages([
                self::FIELD => 'Bu işlem için bir gerekçe yazmalısınız; gerekçesiz işlem yapılamaz.',
            ]);
        }

        return $next($request);
    }

    private function changesState(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function isIgnored(Request $request): bool
    {
        return in_array($request->route()?->getName(), self::IGNORED, true);
    }

    /**
     * Gerekçe istemeyen uçların adları.
     *
     * Denetim testi bu listeyi okur: ekrandaki her durum değiştiren form ya
     * gerekçe toplar ya da adı burada geçer. Üçüncü bir ihtimal yoktur —
     * olsaydı düğme sessizce çalışmazdı.
     *
     * @return list<string>
     */
    public static function ignoredRoutes(): array
    {
        return self::IGNORED;
    }
}
