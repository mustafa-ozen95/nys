<?php

declare(strict_types=1);

namespace App\View\Components\Bgp;

use App\Models\BgpGroup;
use App\Models\BgpGroupFamily;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * BGP grup formunun alanları.
 *
 * Sınıf bileşenidir çünkü hazırlanacak bir veri vardır: adres ailesi
 * satırları. Blade hesap yapmaz — anonim bileşende bu, şablonun içine bir
 *
 * @php bloğu koymak demek olurdu ve proje bunu yasaklar.
 */
class GroupFields extends Component
{
    /**
     * Formda çizilecek adres ailesi satırları.
     *
     * @var list<array{key: string, label: string, current: ?BgpGroupFamily}>
     */
    public array $familyRows;

    /**
     * @param  array<string, string>  $policyOptions
     * @param  array<string, string>  $typeOptions
     * @param  array<string, string>  $nlriOptions
     * @param  array<string, string>  $gracefulRestartOptions
     * @param  array<string, array<string, string>>  $peeringAddressOptions
     */
    public function __construct(
        public ?BgpGroup $group = null,
        public array $policyOptions = [],
        public array $typeOptions = [],
        public array $nlriOptions = [],
        public array $gracefulRestartOptions = [],
        public array $peeringAddressOptions = [],
        public string $prefix = 'yeni',
    ) {
        $this->familyRows = $this->rows();
    }

    /**
     * Grubun taşıdığı aileler, ekrandaki sabit sıraya oturtulmuş hâlde.
     *
     * @return list<array{key: string, label: string, current: ?BgpGroupFamily}>
     */
    private function rows(): array
    {
        $current = $this->group?->families->keyBy(
            fn (BgpGroupFamily $family): string => $family->family->value,
        );

        return [
            ['key' => 'inet', 'label' => 'IPv4', 'current' => $current?->get('inet')],
            ['key' => 'inet6', 'label' => 'IPv6', 'current' => $current?->get('inet6')],
        ];
    }

    public function render(): View
    {
        return view('components.bgp.group-fields');
    }
}
