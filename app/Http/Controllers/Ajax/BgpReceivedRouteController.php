<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Http\Controllers\Controller;
use App\Models\BgpNeighbor;
use App\Models\NetworkSwitch;
use App\Services\Netconf\NetconfClient;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\ReceivedRouteReader;
use Illuminate\Http\JsonResponse;

/**
 * Bir komşudan alınan prefixler: hangileri kabul edildi, hangileri içeri alma
 * politikası tarafından reddedildi.
 *
 * Veri SAKLANMAZ ve eşitleme işine girmez: alınan tablo binlerce satır
 * olabilir ve sürekli değişir. Kullanıcı istediğinde okunur; sayfa her
 * açıldığında cihaza gidilmez (bkz. .ai/rules/netconf.md).
 *
 * Yalnızca okuma yapar, bu yüzden "view" yetkisi ister.
 */
class BgpReceivedRouteController extends Controller
{
    public function __construct(
        private NetconfClient $client,
        private ReceivedRouteReader $reader,
    ) {}

    public function __invoke(NetworkSwitch $switch, BgpNeighbor $bgpNeighbor): JsonResponse
    {
        $this->authorize('view', $switch);

        $routes = $this->client->run(
            $switch,
            fn (NetconfSession $session): array => $this->reader->forNeighbor($session, $bgpNeighbor->address),
        );

        return response()->json([
            'neighbor' => $bgpNeighbor->address,
            'accepted' => $routes['accepted'],
            'rejected' => $routes['rejected'],
        ]);
    }
}
