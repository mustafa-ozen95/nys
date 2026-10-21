@props(['block'])

{{--
    Bloğun izlediği yol: omurga → erişim → hedef. Yalnızca dolu olan halkalar
    gösterilir; hiçbiri yoksa blok bizde tanımlı değildir.

    Ayrı bir "anons" rozeti yoktur: anons, bloğun omurgadaki bir prefix
    list'e girmesidir ve zaten hedef rozetinde listenin adı yazar.
--}}
@if ($block->coreSwitch || $block->accessSwitch)
    <span {{ $attributes->class('assignment') }}>
        @if ($block->coreSwitch)
            <span class="mono assignment__switch">{{ $block->coreSwitch->hostname }}</span>
        @endif

        @if ($block->accessSwitch)
            @if ($block->coreSwitch)
                <span class="assignment__ok" aria-hidden="true">→</span>
            @endif
            <span class="mono assignment__switch">{{ $block->accessSwitch->hostname }}</span>
        @endif

        @if ($block->connection_type)
            <x-ui.badge :variant="$block->connection_type->badge()">{{ $block->assignment_target }}</x-ui.badge>
        @endif
    </span>
@else
    <span {{ $attributes->class('assignment assignment--bos') }}>Atanmadı</span>
@endif
