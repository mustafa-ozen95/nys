<x-layout.app title="Switchler">
    <div class="columns">
        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Switchler</span>
                <span class="column__count">{{ $switches->count() }}</span>

                <x-ui.search name="q" :value="$query"
                            placeholder="Switch adı veya IP" label="Switch filtrele" />

                <span class="spacer"></span>

                {{--
                    BAĞLANTI BİLGİLERİNİN ÇIKTISI.

                    Kapsam ekrandaki süzgeçtir. Bir switchin birden çok kimliği
                    olabiliyor (NETCONF ayrı, SNMP ayrı) ve her biri ayrı bir
                    satır: çıktıyı eline alan kişi hangi yolu kullanacağını
                    seçebilmeli.
                --}}
                @can('exportAny', App\Models\NetworkSwitch::class)
                    <form method="POST" action="{{ route('export.connections') }}"
                          data-confirm="Listedeki switchlerin BAĞLANTI BİLGİLERİ (adres, kullanıcı, parola, SNMP topluluğu) bir Excel dosyasına yazılıp bilgisayarınıza inecek. Dosya panelin dışına çıkar; oradan sonrası panelin denetiminde değildir."
                          data-confirm-title="Bağlantı bilgilerini dosyaya çıkar">
                        @csrf
                        <input type="hidden" name="kapsam" value="switchler">
                        <input type="hidden" name="q" value="{{ $query }}">
                        <button type="submit" class="btn btn--sm"
                                @disabled($switches->isEmpty())>Excel çıktısı ({{ $switches->count() }})</button>
                    </form>
                @endcan

                @can('create', App\Models\NetworkSwitch::class)
                    <button type="button" class="btn btn--primary btn--sm" data-modal-open="switch-add">
                        Yeni switch
                    </button>
                @endcan
            </div>

            <div class="column__body">
                <div class="grid-row__head switch-row switch-row__head">
                    <span class="switch-row__status"></span>
                    <span class="switch-row__name">Switch</span>
                    <span class="switch-row__ip">Yönetim IP</span>
                    <span class="switch-row__role">Rol</span>
                    <span class="switch-row__location">Konum</span>
                    <span class="switch-row__cpu">İşlemci</span>
                    <span class="switch-row__memory">Bellek</span>
                    <span class="switch-row__duration">Çalışma süresi</span>
                    <span class="switch-row__job">Sıcaklık</span>
                </div>

                @forelse ($switches as $switch)
                    <a href="{{ route('switches.show', $switch) }}" class="grid-row switch-row">
                        <x-ui.status-dot :status="$switch->status" :show-label="false" class="switch-row__status" />
                        <span class="mono switch-row__name">{{ $switch->hostname }}</span>
                        <span class="mono text-muted switch-row__ip">{{ $switch->management_ip }}</span>
                        <span class="switch-row__role"><x-ui.badge :variant="$switch->role->badge()">{{ $switch->role->label() }}</x-ui.badge></span>
                        <span class="text-muted switch-row__location">{{ $switch->location->label() }}</span>
                        <x-ui.meter :value="$switch->cpu_usage" metric="cpu" class="switch-row__cpu" data-label="İşlemci" />
                        <x-ui.meter :value="$switch->memory_usage" metric="memory" class="switch-row__memory" data-label="Bellek" />
                        <span class="mono text-muted switch-row__duration"
                              data-label="Çalışma">{{ $switch->uptime_for_humans }}</span>
                        <span class="mono switch-row__job">{{ $switch->temperature ? $switch->temperature.' °C' : '—' }}</span>
                    </a>
                @empty
                    <x-ui.empty title="Switch bulunamadı"
                                text="Filtreyi temizleyin veya yeni bir switch ekleyin." />
                @endforelse
            </div>
        </div>
    </div>

    @can('create', App\Models\NetworkSwitch::class)
        <x-ui.modal id="switch-add" title="Yeni switch" wide>
            <form method="POST" action="{{ route('switches.store') }}"
                  data-confirm="Yeni switch envantere eklenecek."
                  data-confirm-title="Switch ekle">
                @csrf

                <div class="modal__body form-grid">
                    @include('switches.form', ['prefix' => 'yeni'])
                </div>

                <x-ui.modal-actions submit="Switchi ekle" />
            </form>
        </x-ui.modal>
    @endcan
</x-layout.app>
