@props(['report'])

<x-ui.panel title="Anons yetkisi" :subtitle="$report->policy->description()"
            class="announce-permission" :flush="$report->enforced">
    @if ($report->enforced)
        <div class="grid-row__head announce-permission-row">
            <span>Prefix</span>
            <span>Kaynaklar</span>
        </div>

        @foreach ($report->audits as $audit)
            <div class="grid-row announce-permission-row">
                <span class="mono announce-permission-row__prefix">{{ $audit->prefix }}</span>

                <div class="announce-permission-row__sources">
                    @foreach ($audit->verdicts as $verdict)
                        <span class="announce-source">
                            <span class="text-sm text-muted">{{ $verdict->label }}</span>
                            <x-ui.badge :variant="$verdict->state->badge()"
                                        :title="$verdict->detail">{{ $verdict->state->label() }}</x-ui.badge>
                        </span>
                    @endforeach
                </div>

                {{-- Eksik kaynak onaylı blokta da yazılır: gönderimi durdurmaz
                     ama karşı tarafın filtresi çoğunlukla IRR'den üretilir. --}}
                @if ($audit->hasGaps())
                    <p @class([
                        'announce-permission-row__reason',
                        'text-sm',
                        'announce-permission-row__reason--blocked' => ! $audit->approved(),
                    ])>{{ $audit->reason() }}</p>
                @endif
            </div>
        @endforeach
    @else
        <x-ui.alert :variant="$report->allowed ? 'warning' : 'danger'">{{ $report->note }}</x-ui.alert>
    @endif
</x-ui.panel>
