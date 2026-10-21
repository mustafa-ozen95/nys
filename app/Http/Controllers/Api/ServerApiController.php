<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ServerResource;
use App\Models\Server;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Sunucu envanteri.
 *
 * NEDEN VAR: jeton ekranı "servers" kaynağı için yetki verebilir; arkasında
 * uç olmasaydı API tutmadığı bir söz vermiş olurdu. Panel hem switchleri hem
 * sunucuları yönetiyor; ikisinden yalnızca birinin dışarıdan okunabilmesi
 * için bir sebep yok.
 *
 * Liste, isteği yapanın GÖREBİLDİĞİ sunucularla sınırlanır; jetonun kendi
 * yetkisi ayrıca daraltır (bkz. TokenAbilityGate). Yetkisiz bir kayıt listede
 * hiç görünmez — "var ama göremezsin" bile bir bilgidir.
 *
 * Bağlantı parolası ve kullanıcı adı DÖNMEZ (bkz. ServerResource).
 */
class ServerApiController extends Controller
{
    public function __construct(private UserPermissionResolver $resolver) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Server::class);

        $sunucular = $this->resolver
            ->scope(Server::query(), $request->user(), 'servers')
            ->with('latestPasswordRotation')
            ->orderBy('short_name')
            ->paginate((int) $request->integer('per_page', 50));

        return ServerResource::collection($sunucular);
    }

    public function show(Server $server): ServerResource
    {
        $this->authorize('view', $server);

        return new ServerResource($server->load('latestPasswordRotation'));
    }
}
