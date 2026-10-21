<x-layout.app title="Ayarlar">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Ayarlar"
                            subtitle="Ayarı değiştirmeden önce ne olup bittiği. Bölümler üstteki şeritten açılır.">
                <x-slot:actions>
                    <form method="POST" action="{{ route('settings.test') }}"
                          data-confirm="İzleme sistemine tek bir okuma isteği gönderilecek."
                          data-confirm-title="Bağlantıyı sına">
                        @csrf
                        <button type="submit" class="btn btn--sm">Bağlantıyı sına</button>
                    </form>
                </x-slot:actions>
            </x-ui.page-head>

            <x-ui.tabs :items="$sections" />

            <x-ui.flash />

            {{--
                TEK kart: özet ve kaynak tablosu aynı panelde durur.

                Panel flush'tır ve özet kendi boşluğunu taşır (panel__block);
                tablo hücreleri de kendi boşluğunu taşıdığı için ikisi aynı
                yerde başlayıp aynı yerde biter. Panelin gövde boşluğu
                kullanılsaydı özet, tablodan içeride başlardı.
            --}}
            <x-ui.panel title="Durum"
                        subtitle="Hangi kaynak konuşuyor, hangisi kesik, kuyrukta ne var."
                        flush>
                <div class="panel__block">
                    <dl class="facts">
                        <div class="facts__row">
                            <dt class="facts__key">Switch</dt>
                            <dd class="facts__value">{{ $health['switches']['total'] }}</dd>
                        </div>
                        <div class="facts__row">
                            <dt class="facts__key">Bir saattir ölçülemeyen</dt>
                            <dd class="facts__value">
                                @if ($health['switches']['stale'] > 0)
                                    <x-ui.badge variant="yellow">{{ $health['switches']['stale'] }}</x-ui.badge>
                                @else
                                    0
                                @endif
                            </dd>
                        </div>
                        <div class="facts__row">
                            <dt class="facts__key">Kuyrukta bekleyen</dt>
                            <dd class="facts__value">{{ $health['queue']['pending'] ?? '—' }}</dd>
                        </div>
                        <div class="facts__row">
                            <dt class="facts__key">Başarısız iş</dt>
                            <dd class="facts__value">
                                @if (($health['queue']['failed'] ?? 0) > 0)
                                    <x-ui.badge variant="red">{{ $health['queue']['failed'] }}</x-ui.badge>
                                @else
                                    {{ $health['queue']['failed'] ?? '—' }}
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Kaynak</th>
                            <th>Durum</th>
                            <th>Kullanan switch</th>
                            <th>Son başarılı okuma</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($health['sources'] as $source)
                            <tr>
                                <td data-label="Kaynak">
                                    {{ $source['source']->label() }}
                                    @if ($source['primary'])
                                        <x-ui.badge :variant="$source['source']->badge()">Birincil</x-ui.badge>
                                    @endif
                                </td>
                                <td data-label="Durum">
                                    @if (! $source['configured'])
                                        <x-ui.badge variant="gray">Yapılandırılmamış</x-ui.badge>
                                    @elseif ($source['open'])
                                        <x-ui.badge variant="red">Kesik</x-ui.badge>
                                        <span class="text-sm text-muted">{{ $source['reason'] }}</span>
                                    @else
                                        <x-ui.badge variant="green">Hazır</x-ui.badge>
                                    @endif
                                </td>
                                <td class="mono" data-label="Kullanan switch">{{ $source['switches'] }}</td>
                                <td class="mono text-muted" data-label="Son okuma">{{ $source['last_success'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.panel>
        </div>
    </div>
</x-layout.app>
