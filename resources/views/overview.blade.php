<x-layout.app title="Genel bakış">
    <div class="columns">
        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Switchler</span>
                <span class="column__count">{{ $counts['total'] }}</span>

                <span class="spacer"></span>

                <div class="column__hint" aria-hidden="true" title="↑↓ gez · → aç · / filtrele">
                    <span class="kbd">↑↓</span>
                    <span class="kbd">→</span>
                    <span class="kbd">/</span>
                </div>
            </div>

            <div class="column__body">
                <div class="grid-row__head switch-row switch-row__head">
                    <span class="switch-row__status"></span>
                    <span class="switch-row__name">Switch</span>
                    <span class="switch-row__ip">Yönetim IP</span>
                    <span class="switch-row__cpu">İşlemci</span>
                    <span class="switch-row__memory">Bellek</span>
                    <span class="switch-row__job">Sıcaklık</span>
                </div>

                @forelse ($switches as $switch)
                    <a href="{{ route('switches.show', $switch) }}" class="grid-row switch-row">
                        <x-ui.status-dot :status="$switch->status" :show-label="false" class="switch-row__status" />
                        <span class="mono switch-row__name">{{ $switch->hostname }}</span>
                        <span class="mono text-muted switch-row__ip">{{ $switch->management_ip }}</span>
                        <x-ui.meter :value="$switch->cpu_usage" metric="cpu" class="switch-row__cpu" data-label="İşlemci" />
                        <x-ui.meter :value="$switch->memory_usage" metric="memory" class="switch-row__memory" data-label="Bellek" />
                        <span class="mono switch-row__job">{{ $switch->temperature ? $switch->temperature.' °C' : '—' }}</span>
                    </a>
                @empty
                    <x-ui.empty title="Switch yok"
                                text="Görüntüleme yetkiniz olan bir switch bulunmuyor." />
                @endforelse

                @if ($busiestPorts->isNotEmpty())
                    <div class="grid-row__head switch-row switch-row__head overview-section">
                        <span class="switch-row__status"></span>
                        <span class="switch-row__name">En yoğun portlar</span>
                    </div>

                    @foreach ($busiestPorts as $row)
                        <a href="{{ route('switches.ports', ['switch' => $row['port']->switch, 'port' => $row['port']->name]) }}"
                           class="grid-row busy-row">
                            <x-ui.status-dot :status="$row['port']->oper_status" :show-label="false" />
                            <span class="mono busy-row__switch">{{ $row['port']->switch->hostname }}</span>
                            <span class="mono busy-row__port">{{ $row['port']->name }}</span>
                            <span class="truncate text-muted busy-row__description">{{ $row['port']->description ?: '—' }}</span>
                            <span class="mono busy-row__speed">{{ $row['display'] }}</span>
                            <x-ui.meter :value="$row['utilisation']" metric="bandwidth" class="busy-row__fill" />
                        </a>
                    @endforeach
                @endif
            </div>
        </div>

        <aside class="detail">
            <div class="detail__head">
                <div class="row">
                    <span class="dot dot--lg dot--{{ $health['tone'] }}"></span>
                    <span class="detail__title detail__title--plain">{{ $health['label'] }}</span>
                </div>
                <p class="detail__subtitle">
                    {{ $counts['total'] }} switch · {{ $totals['ports'] }} port
                </p>
            </div>

            <div class="detail__body">
                <div class="detail__section">
                    <div class="figures figures--tight">
                        <x-ui.figure :value="$counts['online']" label="Çevrimiçi" tone="green" />
                        <x-ui.figure :value="$counts['warning']" label="Uyarı" tone="yellow" />
                        <x-ui.figure :value="$counts['offline']" label="Erişilemiyor" tone="red" />

                        {{--
                            Ölçüm alınamayan cihaz da sayılır: sayaçların
                            toplamı switch sayısını vermek zorunda, yoksa bir
                            cihaz ekrandan sessizce kayboluyor.
                        --}}
                        <x-ui.figure :value="$counts['unknown']" label="Bilinmiyor" tone="gray" />
                    </div>
                </div>

                <div class="detail__section">
                    <span class="eyebrow">Toplam trafik</span>

                    @if ($totals['available'])
                        <x-ui.facts class="detail__block" :rows="[
                            'Gelen' => $totals['in'],
                            'Giden' => $totals['out'],
                        ]" />
                    @else
                        <p class="detail__block text-muted text-sm">İzleme sisteminden veri alınamadı.</p>
                    @endif
                </div>

                <div class="detail__section">
                    <span class="eyebrow">Eşitleme</span>
                    <x-ui.facts class="detail__block" :rows="[
                        'Son çalışma' => $lastSync?->diffForHumans() ?? 'Henüz çalışmadı',
                    ]" />
                </div>

                @if ($activity->isNotEmpty())
                    <div class="detail__section">
                        <span class="eyebrow">Son hareketler</span>

                        <div class="stack stack--tight detail__block">
                            @foreach ($activity as $entry)
                                <div class="activity-line">
                                    <span class="dot dot--{{ $entry->channel->badge()->value }}"></span>
                                    <div class="truncate">
                                        <div class="truncate">{{ $entry->description }}</div>
                                        <div class="text-sm text-muted truncate">
                                            {{ $entry->user?->name ?? 'Sistem' }}
                                            <span class="mono">· {{ $entry->created_at->diffForHumans() }}</span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </aside>
    </div>
</x-layout.app>
