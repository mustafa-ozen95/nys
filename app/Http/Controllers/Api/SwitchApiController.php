<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\SwitchResource;
use App\Models\NetworkSwitch;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Switch envanteri.
 *
 * Liste, isteği yapanın GÖREBİLDİĞİ switchlerle sınırlanır; jetonun kendi
 * yetkisi ayrıca daraltır (bkz. TokenAbilityGate). Yetkisiz bir kayıt
 * listede hiç görünmez — "var ama göremezsin" bile bir bilgidir.
 */
class SwitchApiController extends Controller
{
    public function __construct(private UserPermissionResolver $resolver) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', NetworkSwitch::class);

        $switches = $this->resolver
            ->scope(NetworkSwitch::query(), $request->user(), 'switches')
            ->withCount(['ports', 'vlans'])
            ->orderBy('hostname')
            ->paginate((int) $request->integer('per_page', 50));

        return SwitchResource::collection($switches);
    }

    public function show(NetworkSwitch $switch): SwitchResource
    {
        $this->authorize('view', $switch);

        return new SwitchResource($switch->loadCount(['ports', 'vlans']));
    }
}
