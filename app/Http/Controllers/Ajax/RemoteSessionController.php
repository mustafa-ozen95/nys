<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Http\Controllers\Controller;
use App\Http\Requests\Remote\RemoteSessionRequest;
use App\Services\ActivityRecorder;
use App\Services\Remote\RemoteSessionOpener;
use App\Services\Remote\RemoteSessionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RemoteSessionController extends Controller
{
    public function __construct(
        private RemoteSessionOpener $opener,
        private RemoteSessionStore $sessions,
        private ActivityRecorder $activity,
    ) {}

    public function connect(RemoteSessionRequest $request): JsonResponse
    {
        $subject = $request->subject();

        // Bağlanma yetkisi görüntüleme yetkisinden ayrıdır.
        $this->authorize('connect', $subject);

        $session = $this->opener->open($subject, $request->user()->id);

        $this->activity->record(
            $subject,
            'session_opened',
            "Uzak oturum açıldı: {$session['label']}",
            $session['channel'],
        );

        return response()->json([
            'token' => $session['token'],
            'label' => $session['label'],
            'protocol' => $session['protocol'],
            // Oturum sarmalayıcı sayfası; iframe'i o kurar.
            'url' => route('remote.session', ['token' => $session['token']]),
        ]);
    }

    /**
     * OTURUM SAHİBİNDEN BAŞKASI CANLI TUTAMAZ.
     *
     * Tek kimlik jetonun kendisi olsaydı, yetkisi geri alınmış birinin açık
     * kalan penceresi ttyd sürecini süresiz canlı tutabilirdi. Jeton kimin
     * diye sorulmadan uzatmak, oturumu ömürsüz yapmak demektir.
     *
     * Yanıt 403 DEĞİL "alive: false": ön yüz oturumu bitmiş sayıp kapatır ve
     * jetonun var olup olmadığı da söylenmemiş olur.
     */
    public function keepAlive(Request $request): JsonResponse
    {
        $token = $request->string('token')->toString();
        $up = $this->isOwner($request, $token) && $this->sessions->touch($token);

        /* Denetim izi de ses veriyor; zamanlayıcı bunu "hâlâ açık" sayar. */
        if ($up) {
            $this->opener->touchTrace($token);
        }

        return response()->json(['alive' => $up]);
    }

    /**
     * OTURUMU YALNIZCA SAHİBİ KAPATIR.
     *
     * Kimlik sorulmasaydı, elinde bir jeton olan başkasının oturumunu
     * kapatabilirdi. Sahibi olmayan için oturum YOKTUR: 404 döner, "var ama
     * senin değil" denmez.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $token = $request->string('token')->toString();

        abort_unless($this->isOwner($request, $token), 404);

        $session = $this->opener->close($token);

        // Kapatma da kayda geçer: bir oturumun ne zaman bittiği açılışı kadar
        // önemlidir.
        if ($session !== null) {
            $this->activity->record(
                $session['subject'],
                'session_closed',
                "Uzak oturum kapatıldı: {$session['label']}",
                $session['channel'],
            );
        }

        return response()->json(['closed' => true]);
    }

    /** Bu jeton isteği yapan kişinin oturumuna mı ait? */
    private function isOwner(Request $request, string $token): bool
    {
        $session = $this->sessions->get($token);

        return $session !== null && (int) ($session['user_id'] ?? 0) === (int) $request->user()->id;
    }
}
