<x-layout.app title="BGP">
    <div class="columns">
        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">BGP komşuları</span>
                <span class="column__count">{{ $summary['established'] }}/{{ $summary['total'] }}</span>

                <x-ui.search name="q" :value="$query"
                            placeholder="Komşu, grup, ASN veya switch" label="Komşu filtrele" />
            </div>

            <div class="column__body">
                <div class="grid-row__head bgp-row bgp-row__head">
                    <span class="bgp-row__status"></span>
                    <span class="bgp-row__neighbor">Komşu</span>
                    <span class="bgp-row__asn">Komşu ASN</span>
                    <span class="bgp-row__switch">Switch</span>
                    <span class="bgp-row__group">Grup</span>
                    <span class="bgp-row__description">Açıklama</span>
                </div>

                @forelse ($neighbors as $neighbor)
                    <a href="{{ route('bgp.index', ['q' => $query ?: null, 'neighbor' => $neighbor->uuid]) }}"
                       class="grid-row bgp-row"
                       @if ($selected?->is($neighbor)) aria-selected="true" @endif>
                        <span class="bgp-row__status">
                            <x-ui.badge :variant="$neighbor->state->badge()">{{ $neighbor->state->label() }}</x-ui.badge>
                        </span>
                        <span class="mono bgp-row__neighbor">{{ $neighbor->address }}</span>
                        <span class="mono bgp-row__asn">{{ $neighbor->effective_peer_asn }}</span>
                        <span class="mono text-muted bgp-row__switch">{{ $neighbor->switch->hostname }}</span>
                        <span class="mono text-muted bgp-row__group">{{ $neighbor->group->name }}</span>
                        <span class="text-muted truncate bgp-row__description">{{ $neighbor->description }}</span>
                    </a>
                @empty
                    <x-ui.empty title="BGP komşusu yok"
                                text="Komşular cihazdan eşitlenir. Yapılandırma switchin kendi BGP ekranından yapılır." />
                @endforelse
            </div>
        </div>

        @if ($selected)
            <aside class="detail">
                <div class="detail__head">
                    <div class="detail__title mono">{{ $selected->address }}</div>
                    <p class="detail__subtitle">{{ $selected->description ?: '—' }}</p>
                </div>

                <div class="detail__body">
                    <div class="detail__section">
                        <span class="eyebrow">Oturum</span>
                        <x-ui.facts class="detail__block" :rows="[
                            'Durum' => $selected->state->label(),
                            'Karşı taraf AS' => $selected->effective_peer_asn,
                            'Yerel adres' => $selected->local_address ?? $selected->group->local_address,
                            'Son değişiklik' => $selected->state_changed_for,
                            'Flap' => $selected->flap_count,
                        ]" />
                    </div>

                    <div class="detail__section">
                        <span class="eyebrow">Prefix</span>
                        <x-ui.facts class="detail__block" :rows="[
                            'Alınan' => $selected->received_prefixes,
                            'Kabul edilen' => $selected->accepted_prefixes,
                            'Etkin' => $selected->active_prefixes,
                        ]" />
                    </div>

                    <div class="detail__section">
                        <span class="eyebrow">Grup</span>
                        <x-ui.facts class="detail__block" :rows="[
                            'Ad' => $selected->group->name,
                            'Tür' => $selected->group->type->label(),
                            'Switch' => $selected->switch->hostname,
                            'İçeri alma' => $selected->group->import_policy_list ?: null,
                            'Dışarı verme' => $selected->group->export_policy_list ?: null,
                        ]" />
                    </div>
                </div>

                {{-- Yönetim tek yerdedir: komşunun bağlı olduğu switchin BGP ekranı. --}}
                <div class="detail__foot">
                    <a href="{{ route('switches.bgp', $selected->switch) }}" class="btn btn--primary btn--sm">
                        Switchte yönet
                    </a>
                </div>
            </aside>
        @endif
    </div>
</x-layout.app>
