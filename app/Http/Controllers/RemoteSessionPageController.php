<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Remote\RemoteSessionStore;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Açık bir uzak oturumu gösteren sarmalayıcı sayfa.
 *
 * Oturum jetonu önbellekte durur; süresi dolmuşsa ya da başkasına aitse
 * sayfa açılmaz. Yetki her istekte yeniden sorulur: aradan geçen sürede
 * kullanıcının yetkisi geri alınmış olabilir.
 */
class RemoteSessionPageController extends Controller
{
    public function __construct(private RemoteSessionStore $sessions) {}

    public function __invoke(Request $request, string $token): View
    {
        $session = $this->sessions->get($token);

        abort_if($session === null, Response::HTTP_NOT_FOUND, 'Oturum bulunamadı veya süresi doldu.');

        $subject = $session['subject_type']::query()->find($session['subject_id']);

        abort_if($subject === null, Response::HTTP_NOT_FOUND);
        $this->authorize('connect', $subject);

        return view('remote.session', [
            'session' => $session,
            'token' => $token,
        ]);
    }
}
