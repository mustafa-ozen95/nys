<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Http\Controllers\Controller;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\Vlan;
use Illuminate\Http\JsonResponse;

/**
 * Bir switchin route hedefleri: VLAN'ları ve portları.
 *
 * Adresleme ekranındaki seçim listelerini doldurur; kullanıcı VLAN etiketi
 * veya port adı yazmaz, var olanlardan seçer.
 */
class SwitchTargetController extends Controller
{
    public function __invoke(NetworkSwitch $switch): JsonResponse
    {
        $this->authorize('view', $switch);

        return response()->json([
            /*
             * Hedefin değeri VLAN'ın UUID'sidir, etiketi değil: etiket tekil
             * ama kalıcı değil ve cihazda etiketsiz VLAN da geçerli. Sıralama
             * etikete göre kalır; etiketsizler sona düşer.
             */
            'vlans' => $switch->vlans()
                ->orderByRaw('vlan_tag is null')
                ->orderBy('vlan_tag')
                ->get()
                ->map(fn (Vlan $vlan): array => [
                    'value' => $vlan->uuid,
                    'label' => $vlan->label,
                ])
                ->all(),

            'ports' => $switch->ports()
                ->orderBy('if_index')
                ->get()
                ->map(fn (SwitchPort $port): array => [
                    'value' => $port->name,
                    'label' => $port->description ? "{$port->name} · {$port->description}" : $port->name,
                ])
                ->all(),
        ]);
    }
}
