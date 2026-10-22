<x-layout.app :title="$group['label'].' · Ayarlar'">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Ayarlar" :subtitle="$group['description']">
                {{--
                    Dış bir servise bağlanan bölümlerde sınama düğmesi. Yalnızca
                    OKUR; hiçbir ayarı ve hiçbir kaydı değiştirmez.
                --}}
                @if ($group['test'])
                    <x-slot:actions>
                        <form method="POST" action="{{ route($group['test']['route']) }}"
                              data-confirm="{{ $group['test']['confirm'] }}"
                              data-confirm-title="{{ $group['test']['label'] }}">
                            @csrf
                            <button type="submit" class="btn btn--sm">{{ $group['test']['label'] }}</button>
                        </form>
                    </x-slot:actions>
                @endif
            </x-ui.page-head>

            <x-ui.tabs :items="$sections" />

            <x-ui.flash />

            {{--
                Yalnızca bu bölüm gönderilir. SettingsRequest istekte bulunan
                alanları işler; gönderilmeyen ayar "boşaltıldı" sayılmaz.
            --}}
            <form method="POST" action="{{ route('settings.update', $group['slug']) }}"
                  data-confirm="{{ $group['label'] }} ayarları değiştirilecek."
                  data-confirm-title="{{ $group['label'] }}"
                  data-confirm-verify="{{ $group['label'] }}">
                @csrf
                @method('PUT')

                <x-ui.panel :title="$group['label']">
                    {{-- Basit mod'un kendi düzeni var: 28 bölüm anahtarı ve 167 alan düz listede okunamazdı. --}}
                    @if ($group['slug'] === 'simple-mode')
                        @include('settings.partials.simple-mode')
                    @else
                        <div class="form-grid">
                            @foreach ($group['items'] as $item)
                                <x-settings.field :item="$item" :value="$values[$item->key]" />
                            @endforeach
                        </div>
                    @endif

                    @if ($audit !== [])
                        {{--
                            Varsayılandan sapan ayarlar. Bir değerin neden
                            böyle olduğunu soran kişi, kimin ne zaman
                            değiştirdiğini burada görür.
                        --}}
                        <dl class="facts detail__block">
                            @foreach ($audit as $satir)
                                <div class="facts__row">
                                    <dt class="facts__key">{{ $satir['label'] }}</dt>
                                    <dd class="facts__value">{{ $satir['by'] ?? 'sistem' }} · {{ $satir['at'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    <div class="form-actions">
                        <button type="submit" class="btn btn--primary">Kaydet</button>
                    </div>
                </x-ui.panel>
            </form>
        </div>
    </div>
</x-layout.app>
