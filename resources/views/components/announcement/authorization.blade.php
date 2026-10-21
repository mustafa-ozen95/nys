@props(['report'])

<x-ui.panel title="Anons yetkisi" :subtitle="$report->policy->description()"
            class="anons-yetki" :flush="$report->enforced">
    @if ($report->enforced)
        <div class="grid-row__head anons-yetki-row">
            <span>Prefix</span>
            <span>Kaynaklar</span>
        </div>

        @foreach ($report->audits as $audit)
            <div class="grid-row anons-yetki-row">
                <span class="mono anons-yetki-row__prefix">{{ $audit->prefix }}</span>

                <div class="anons-yetki-row__kaynaklar">
                    @foreach ($audit->verdicts as $verdict)
                        <span class="anons-kaynak">
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
                        'anons-yetki-row__gerekce',
                        'text-sm',
                        'anons-yetki-row__gerekce--engel' => ! $audit->approved(),
                    ])>{{ $audit->reason() }}</p>
                @endif
            </div>
        @endforeach
    @else
        <x-ui.alert :variant="$report->allowed ? 'warning' : 'danger'">{{ $report->note }}</x-ui.alert>
    @endif
</x-ui.panel>
