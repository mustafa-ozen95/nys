{{--
    Ağaçtaki tek blok satırı.

    Satır hem BAĞLANTI hem de ağacın özeti olabiliyor; ikisinde de aynı
    görünmeli, yoksa aynı blok iki yerde iki türlü okunurdu.

    ATANMIŞ BLOK AÇIKÇA SÖYLENİR: "kullanımda — başka yere atanamaz". Bunu
    yazmayan bir liste, kullanıcıyı aynı aralığı ikinci kez vermeye davet eder
    ve bu ekranın varlık sebebi tam da onu önlemek.
--}}
@props(['block', 'selected' => null])

<a href="{{ route('addressing.index', ['block' => $block->uuid]) }}"
   class="grid-row block-row agac__row"
   @if ($selected?->is($block)) aria-selected="true" @endif>
    <span class="mono block-row__cidr">{{ $block->cidr }}</span>

    <span class="text-sm text-muted truncate block-row__description">
        @if ($block->purpose?->isInfrastructure())
            <x-ui.badge :variant="$block->purpose->badge()">{{ $block->purpose->label() }}</x-ui.badge>
        @endif
        {{ $block->description }}
    </span>

    <span class="block-row__assignment">
        @if ($block->is_assigned)
            <x-ui.badge variant="orange">Kullanımda</x-ui.badge>
        @endif
        <x-net.assignment :block="$block" />
    </span>

    <span class="text-sm text-muted block-row__number">
        @if ($block->is_subnetted)
            {{ $block->subnets_count ?? $block->subnets()->count() }} alt ağ
        @elseif ($block->is_assigned)
            atanamaz
        @endif
    </span>
</a>
