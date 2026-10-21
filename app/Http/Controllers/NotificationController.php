<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panelin bildirim akışı.
 *
 * Yetkilendirme: kullanıcı yalnızca GÖREBİLDİĞİ switchlerin olaylarını
 * görür — süzme NotificationFeed içinde visibleTo ile yapılır. Ayrı bir
 * yetki aranmaz: burada okunan şey, kullanıcının zaten görebildiği
 * ekranlarda duran bilgidir.
 */
class NotificationController extends Controller
{
    public function __construct(private NotificationFeed $feed) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->feed->forUser($request->user()));
    }

    /**
     * "Okundu" kişiye özeldir: biri baktı diye ötekinin sayacı sıfırlanmaz.
     */
    public function seen(Request $request): JsonResponse
    {
        $request->user()->forceFill(['notifications_seen_at' => now()])->save();

        return response()->json(['unseen' => 0]);
    }
}
