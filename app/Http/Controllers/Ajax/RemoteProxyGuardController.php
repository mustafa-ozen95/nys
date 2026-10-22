<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Enums\ConnectionProtocol;
use App\Http\Controllers\Controller;
use App\Services\Remote\RemoteSessionStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Uzak oturum proxy'lerinin bekçisi.
 *
 * Yetkilendirme: kontrolü kendisi yapar. "auth" ara katmanının dışındadır
 * çünkü nginx auth_request yönlendirmeyi (302) anlamaz; kimliksiz isteğe
 * doğrudan 401 döner.
 *
 * nginx, /ssh-proxy/ ve /rdp-proxy/ altındaki her isteği önce buraya sorar
 * (auth_request). Bunsuz ttyd portları kimlik doğrulaması olmadan
 * erişilebilirdi: açık bir oturum sırasında port aralığını tarayan biri
 * terminale düşebilirdi.
 *
 * Yetki her istekte yeniden sorulur; oturum sahibi değişmez ve aradan geçen
 * sürede kullanıcının bağlanma yetkisi geri alınmış olabilir.
 *
 * HESABIN ETKİN OLDUĞU DA burada sorulur. "auth" katmanının dışında olduğumuz
 * için EnsureUserIsActive bu yola hiç uğramıyor: pasifleştirilmiş bir kullanıcı
 * panelde her sayfada oturumdan atılırken, AÇIK DURAN terminali çalışmaya devam
 * ediyordu. Hesabı kapatmak anında etkili olmalı.
 */
class RemoteProxyGuardController extends Controller
{
    public function __construct(private RemoteSessionStore $sessions) {}

    public function __invoke(Request $request): Response
    {
        // nginx auth_request yalnızca 2xx/401/403 anlar; başka her yanıtı
        // 500'e çevirir. Bu yüzden bu uç login'e yönlendirmez, 401 döner.
        if ($request->user() === null) {
            return response()->noContent(Response::HTTP_UNAUTHORIZED);
        }

        if (! $request->user()->isActive()) {
            return response()->noContent(Response::HTTP_FORBIDDEN);
        }

        $target = $this->requestedTarget($request);

        // Adres kurallı biçimde okunamıyorsa nginx'in sunacağı kaynakla bizim
        // yetkilendirdiğimiz kaynak ayrışabilir; bu durumda hiçbir şey sorulmaz.
        if ($target === false) {
            return response()->noContent(Response::HTTP_FORBIDDEN);
        }

        $port = $target;

        $session = $port === null
            ? $this->sessions->forUser($request->user()->id, ConnectionProtocol::Rdp)
            : $this->sessions->forPort($port);

        if ($session === null || $session['user_id'] !== $request->user()->id) {
            return response()->noContent(Response::HTTP_FORBIDDEN);
        }

        $subject = $session['subject_type']::query()->find($session['subject_id']);

        if ($subject === null || $request->user()->cannot('connect', $subject)) {
            return response()->noContent(Response::HTTP_FORBIDDEN);
        }

        return response()->noContent();
    }

    /**
     * Hangi ttyd portunun sorulduğu (null: RDP proxy'si, false: reddedilmeli).
     *
     * nginx alt isteğinde sorgu parametresi güvenilir biçimde taşınmıyor;
     * bunun yerine istemcinin özgün adresi X-Original-Uri başlığıyla
     * geliyor ve port oradan okunuyor. RDP proxy'sinde port yoktur.
     *
     * Başlık HAM $request_uri'dir; nginx ise konumu çözülmüş ve normalleştirilmiş
     * $uri'ye göre seçer. "/ssh-proxy/7701/../7700/" gibi bir yol burada 7701
     * görünür, nginx'te ise 7700'e gider: yetki bir portta verilir, başka
     * port sunulurdu. Bu yüzden yolun normalleştirmeyle DEĞİŞMEYECEĞİ garanti
     * edilir: nokta parçası, kodlanmış karakter, ters eğik çizgi ve çift
     * eğik çizgi içeren her yol reddedilir. Böylece ham yol ile nginx'in
     * baktığı yol aynıdır. (nginx tarafındaki aynı kural nsm.conf'ta.)
     */
    private function requestedTarget(Request $request): int|false|null
    {
        if ($request->filled('port')) {
            return $request->integer('port');
        }

        if (! $request->headers->has('X-Original-Uri')) {
            return null;
        }

        $path = explode('?', (string) $request->header('X-Original-Uri'), 2)[0];

        if (preg_match('#[%\\\\\x00-\x20\x7f]|//#', $path) === 1
            || in_array('..', explode('/', $path), true)
            || in_array('.', explode('/', $path), true)) {
            return false;
        }

        if (preg_match('#^/ssh-proxy/([1-9]\d{3})(?:/|$)#', $path, $match) === 1) {
            return (int) $match[1];
        }

        return str_starts_with($path, '/rdp-proxy/') ? null : false;
    }
}
