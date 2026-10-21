<?php

declare(strict_types=1);

namespace App\View\Components\Addressing;

use App\Models\AddressBlock;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Adres bloğu atama formunun alanları.
 *
 * Sınıf bileşenidir çünkü hazırlanacak bir karar vardır: kayıtlı sonraki
 * atlama envanterdeki uplink'lerden biri mi, yoksa elle mi girilmiş. Blade
 * hesap yapmaz — anonim bileşende bu, şablonun içine bir @php bloğu koymak
 * demek olurdu ve proje bunu yasaklar.
 */
class AssignmentFields extends Component
{
    /**
     * Kayıtlı sonraki atlama elle mi girilmiş?
     *
     * Envanterdeki uplink adreslerinden biri değilse elle girilmiştir ve
     * form o alanı açık getirir; aksi hâlde kullanıcı kendi yazdığı adresi
     * kaybeder.
     */
    public bool $manualNextHop;

    /**
     * @param  array<string, string>  $coreSwitchOptions
     * @param  array<string, string>  $accessSwitchOptions
     * @param  array<string, string>  $typeOptions
     * @param  array<string, string>  $prefixListOptions
     * @param  array<string, string>  $uplinkOptions
     */
    public function __construct(
        public array $coreSwitchOptions = [],
        public array $accessSwitchOptions = [],
        public array $typeOptions = [],
        public array $prefixListOptions = [],
        public array $uplinkOptions = [],
        public ?AddressBlock $block = null,
        public string $prefix = 'blok',
    ) {
        $this->manualNextHop = $block?->next_hop !== null
            && ! array_key_exists($block->next_hop, $uplinkOptions);
    }

    public function render(): View
    {
        return view('components.addressing.assignment-fields');
    }
}
