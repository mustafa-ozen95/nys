<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Http\Controllers\Controller;
use App\Models\AddressBlock;
use App\Services\Addressing\AddressAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bir bloğun altındaki boş alt ağ yuvaları.
 *
 * Hesap sunucuda yapılır; tarayıcı yalnızca listeyi basar.
 */
class AddressSlotController extends Controller
{
    public function __construct(private AddressAllocationService $allocation) {}

    public function __invoke(Request $request, AddressBlock $block): JsonResponse
    {
        $this->authorize('view', $block);

        $request->validate([
            // Üst sınır aileye bağlı; IPv6'da /128'e kadar bölünebilir.
            'prefix' => ['required', 'integer', 'min:1', 'max:128'],
        ]);

        $prefix = $request->integer('prefix');

        abort_if($prefix <= $block->prefix_length, 422, 'Alt ağ prefix’i üst bloktan büyük olmalı.');

        return response()->json([
            'parent' => $block->cidr,
            'slots' => $this->allocation->slots($block, $prefix),
        ]);
    }
}
