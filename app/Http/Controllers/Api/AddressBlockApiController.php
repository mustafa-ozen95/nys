<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AddressBlockResource;
use App\Models\AddressBlock;
use App\Services\Addressing\AddressLayoutService;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Adresleme: hangi ağ kime ait, hangi adres boşta.
 *
 * NEDEN VAR: jeton ekranı "addressing" kaynağı için yetki verebilir; bu uç o
 * yetkinin karşılığıdır. Adreslemenin var olma sebebi tam da bu soruya cevap
 * vermek — hangi adresler kullanılıyor, hangileri boşta, çakışma olmasın — ve
 * o cevabı yalnızca ekranda vermek, envanterini başka bir yerde tutan herkesi
 * dışarıda bırakırdı.
 *
 * KULLANIM sayfası (hosts) AYRI BİR UÇ: liste ucunda dönseydi her blok için
 * adres adres tarama yapılır, yüzlerce bloklu bir kurulumda tek istek
 * dakikalar sürerdi. Panelin ekranı da aynı ayrımı yapıyor.
 */
class AddressBlockApiController extends Controller
{
    public function __construct(
        private UserPermissionResolver $resolver,
        private AddressLayoutService $layout,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AddressBlock::class);

        $blocks = $this->resolver
            ->scope(AddressBlock::query(), $request->user(), 'addressing')
            ->with(['accessSwitch', 'coreSwitch', 'vlan'])
            ->withCount('subnets')
            ->orderBy('range_start')
            ->paginate((int) $request->integer('per_page', 50));

        return AddressBlockResource::collection($blocks);
    }

    public function show(AddressBlock $addressBlock): AddressBlockResource
    {
        $this->authorize('view', $addressBlock);

        return new AddressBlockResource(
            $addressBlock->load(['accessSwitch', 'coreSwitch', 'vlan'])->loadCount('subnets'),
        );
    }

    /**
     * Bloğun içindeki adresler ve KİM tuttuğu.
     *
     * Kaynak tek tablo değil: bir adres switch arayüzünde, bir sunucuda ya da
     * bir BGP komşusunda duruyor olabilir. Cevap panelin ekranıyla AYNI
     * servisten gelir; ikinci bir hesap yeri açsaydık ikisi ayrışırdı.
     *
     * Çok büyük bloklarda liste verilmez ("limited": true): /8 bir ağı adres
     * adres dökmek ne bize ne isteyene yarar.
     */
    public function hosts(AddressBlock $addressBlock): JsonResponse
    {
        $this->authorize('view', $addressBlock);

        $result = $this->layout->hosts($addressBlock);

        return response()->json([
            'data' => [
                'cidr' => $addressBlock->network.'/'.$addressBlock->prefix_length,
                'limited' => $result['sinirli'],
                'total' => $result['toplam'],
                'used' => $result['dolu'],
                'free' => $result['sinirli'] || $result['toplam'] === null
                    ? null
                    : $result['toplam'] - $result['dolu'],
                'addresses' => array_map(static fn (array $a): array => [
                    'address' => $a['adres'],
                    'owner' => $a['sahip'],
                    'owner_type' => $a['tur'],
                ], $result['adresler']),
            ],
        ]);
    }
}
