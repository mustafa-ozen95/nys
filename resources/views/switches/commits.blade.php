<x-layout.app :title="$switch->hostname.' · Commit geçmişi'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Commit geçmişi</span>
                <span class="column__count">{{ $commits->count() }}</span>
                <span class="spacer"></span>
                <span class="text-sm text-muted">Cihazdan okunur, elle girilmez</span>
            </div>

            <div class="column__body">
                <x-ui.flash />

                {{--
                    Geri alma iki adımdır: önce fark okunur, geri alma o
                    farkın altından yapılır. Geri alma cihazın TAMAMINI eski
                    hâline döndürür — panelin yönetmediği satırlar da dahil —
                    ve ne olacağını görmeden atılacak bir commit değildir.
                --}}
                @if ($preview !== null)
                    <x-ui.panel :title="'Geri alma farkı · '.$preview['label']"
                                subtitle="Aşağıdaki değişiklik cihaza uygulanacak. Henüz hiçbir şey yazılmadı.">
                        <x-ui.alert variant="warning">
                            Geri alma cihazın tamamını etkiler: panelin yönetmediği satırlar da
                            bu commit’teki hâline döner.
                        </x-ui.alert>

                        <pre class="config-preview">{{ $preview['diff'] ?: 'Fark yok: cihaz zaten bu yapılandırmada.' }}</pre>

                        @foreach ($preview['warnings'] as $uyari)
                            <p class="text-sm text-muted">{{ $uyari }}</p>
                        @endforeach

                        @if ($preview['diff'] !== '')
                            <form method="POST" action="{{ $preview['action'] }}"
                                  data-confirm="{{ $switch->hostname }} cihazı {{ $preview['label'] }} commit’ine döndürülecek. Geri alınabilir commit kullanılır: onay verilemezse cihaz kendini eski hâline döndürür."
                                  data-confirm-title="Cihazı geri al"
                                  data-confirm-variant="danger"
                                  data-confirm-verify="{{ $switch->hostname }}">
                                @csrf
                                @method('PUT')
                                <div class="form-actions">
                                    <button type="submit" class="btn btn--danger btn--sm">Geri al</button>
                                </div>
                            </form>
                        @endif
                    </x-ui.panel>
                @endif

                <div class="grid-row__head commit-row commit-row__head">
                    <span class="commit-row__sira">#</span>
                    <span class="commit-row__zaman">Zaman</span>
                    <span class="commit-row__kim">Yapan</span>
                    <span class="commit-row__not">Açıklama</span>
                    <span class="commit-row__eylem"></span>
                </div>

                @forelse ($commits as $commit)
                    <div class="grid-row grid-row--static commit-row">
                        <span class="mono commit-row__sira">{{ $commit->sequence_number }}</span>
                        <span class="mono text-muted commit-row__zaman">
                            <x-ui.time :at="$commit->committed_at" format="d.m.Y H:i" />
                        </span>
                        <span class="mono commit-row__kim">{{ $commit->committed_by ?: '—' }}</span>
                        <span class="text-muted commit-row__not">{{ $commit->comment ?: '—' }}</span>

                        <span class="commit-row__eylem">
                            @can('provision', $switch)
                                @if ($commit->sequence_number > 0)
                                    <form method="POST"
                                          action="{{ route('switches.commits.preview', [$switch, $commit]) }}">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn--sm">Farkı gör</button>
                                    </form>
                                @else
                                    <span class="text-sm text-muted">Şu anki</span>
                                @endif
                            @endcan
                        </span>
                    </div>
                @empty
                    <x-ui.empty title="Commit kaydı yok"
                                text="Bu liste cihazdan NETCONF ile okunur. Henüz eşitleme yapılmadıysa network:sync-netconf çalıştırın." />
                @endforelse
            </div>
        </div>
    </div>
</x-layout.app>
