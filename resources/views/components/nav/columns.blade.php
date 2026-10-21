@props(['switches' => [], 'switch' => null, 'objects' => []])

{{-- Sütunlu gezinmenin sabit iki sütunu: switchler ve seçili switchin nesneleri. --}}
<div class="column column--narrow">
    <div class="column__head">
        <span class="eyebrow">Switch</span>
        <span class="column__count">{{ count($switches) }}</span>
    </div>

    <div class="column__body">
        @foreach ($switches as $item)
            <a href="{{ route('switches.show', $item) }}"
               class="row-item"
               @if ($switch && $item->is($switch)) aria-selected="true" @endif>
                <x-ui.status-dot :status="$item->status" :show-label="false" />
                <span class="row-item__label row-item__label--mono">{{ $item->hostname }}</span>
            </a>
        @endforeach
    </div>
</div>

<div class="column column--narrow">
    <div class="column__head">
        <span class="eyebrow">Nesne</span>
    </div>

    <div class="column__body">
        @foreach ($objects as $object)
            @if ($object['url'])
                <a href="{{ $object['url'] }}"
                   class="row-item"
                   @if ($object['active']) aria-selected="true" @endif>
                    <span class="row-item__label">{{ $object['label'] }}</span>
                    @if ($object['count'] !== null)
                        <span class="row-item__meta">{{ $object['count'] }}</span>
                    @endif
                </a>
            @else
                <span class="row-item row-item--muted" aria-disabled="true"
                      title="Veri kaynağı henüz bağlanmadı">
                    <span class="row-item__label">{{ $object['label'] }}</span>
                    <span class="row-item__meta">—</span>
                </span>
            @endif
        @endforeach
    </div>
</div>
