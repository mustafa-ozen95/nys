<x-layout.app :title="$switch->hostname.' · Port aynalama'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Port aynalama</span>
                <span class="column__count">{{ $analyzers->count() }}</span>
                <span class="spacer"></span>

                @can('createSwitchMirroring', $switch)
                    <button type="button" class="btn btn--primary btn--sm"
                            data-modal-open="mirror-add">Aynalama ekle</button>
                @endcan
            </div>

            <div class="column__body">
                <x-ui.flash />

                {{--
                    Aynalama bir TEŞHİS aracıdır: seçilen kaynağın trafiği
                    olduğu gibi kopyalanır. Bu yüzden aynı zamanda bir dinleme
                    yoludur ve kendi yetkisini taşır; spanning tree ile
                    fırtına denetiminin (port koruması) altında değildir.
                --}}
                <x-ui.panel title="Aynalama nasıl çalışır"
                            subtitle="Seçilen portların ya da VLAN’ların trafiği tek bir hedefe kopyalanır.">
                    <x-ui.alert variant="warning">
                        Aynalanan trafiğin tamamı hedefte görünür — parolalar ve kişisel veriler dâhil.
                        Hedef porta erişebilen herkes kaynak portun trafiğini okuyabilir.
                    </x-ui.alert>

                    <div class="stack stack--tight">
                        <p class="text-sm text-muted">Çıkış hedefi ya bir <strong>arayüz</strong> ya bir
                            <strong>VLAN</strong>’dır; ikisi birlikte verilemez.</p>
                        <p class="text-sm text-muted">Çıkış portu anahtarlama ailesinde olmalıdır; cihaz aksini
                            <span class="mono">“should be configured with family ethernet-switching”</span>
                            diyerek reddeder.</p>
                        <p class="text-sm text-muted">Kaynak port çıkış portu olamaz.</p>
                        <p class="text-sm text-muted">Kopyalanan trafik hedef portun hızını aşarsa fazlası düşer;
                            aynalama gerçek trafiği yavaşlatmaz ama kopyayı eksik gösterir.</p>
                    </div>
                </x-ui.panel>

                <div class="grid-row__head setting-row">
                    <span>Ad</span>
                    <span>Hedef</span>
                    <span>Kaynaklar</span>
                    <span></span>
                </div>

                @forelse ($analyzers as $analyzer)
                    <div class="grid-row grid-row--static setting-row">
                        <span class="mono">{{ $analyzer->name }}</span>

                        <span>
                            <span class="mono">{{ $analyzer->output_label }}</span>
                            @if ($analyzer->is_idle)
                                <x-ui.badge variant="yellow" title="Kaynak seçilmediği için hiçbir şey kopyalanmıyor">Boşta</x-ui.badge>
                            @endif
                        </span>

                        <span class="text-sm text-muted">
                            {{ $analyzer->source_summary }}

                            @if ($analyzer->ratio)
                                <x-ui.badge variant="gray" title="Kaç pakette bir kopyalanıyor">1/{{ $analyzer->ratio }}</x-ui.badge>
                            @endif

                            @if ($analyzer->preserved_lines)
                                <x-ui.badge variant="cyan"
                                            title="Panelde gösterilmeyen ama korunan satırlar: {{ implode(', ', $analyzer->preserved_lines) }}">{{ count($analyzer->preserved_lines) }} korunan satır</x-ui.badge>
                            @endif
                        </span>

                        <span class="row-actions">
                            @can('updateSwitchMirroring', $switch)
                                <button type="button" class="btn btn--sm"
                                        data-modal-open="aynalama-{{ $analyzer->uuid }}">Düzenle</button>
                            @endcan

                            @can('deleteSwitchMirroring', $switch)
                                <x-ui.delete-form :action="route('switches.mirroring.destroy', [$switch, $analyzer])"
                                                  confirm="{{ $analyzer->name }} aynalaması CİHAZDAN silinecek; trafik kopyalanmayı durdurur."
                                                  title="Aynalamayı sil"
                                                  verify="{{ $analyzer->name }}" />
                            @endcan
                        </span>
                    </div>
                @empty
                    <x-ui.empty title="Aynalama tanımlı değil"
                                text="Cihazda hiçbir portun trafiği kopyalanmıyor. Bir sorunu izlemek için kaynak port ve hedef port seçin." />
                @endforelse
            </div>
        </div>
    </div>

    @can('createSwitchMirroring', $switch)
        <x-ui.modal id="mirror-add" title="Aynalama ekle" wide>
            <form method="POST" action="{{ route('switches.mirroring.store', $switch) }}"
                  data-confirm="Aynalama cihaza yazılacak. Seçilen kaynakların bütün trafiği hedefe kopyalanır."
                  data-confirm-title="Cihaza yaz"
                  data-confirm-verify="{{ $switch->hostname }}">
                @csrf
                <div class="modal__body stack">
                    <x-switching.analyzer-fields prefix="aynalama-yeni"
                                                 :source-options="$sourceInterfaceOptions"
                                                 :output-options="$outputInterfaceOptions"
                                                 :output-vlan-options="$outputVlanOptions"
                                                 :vlan-options="$vlanOptions"
                                                 :loss-priority-options="$lossPriorityOptions" />
                </div>
                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>
    @endcan

    @can('updateSwitchMirroring', $switch)
        @foreach ($analyzers as $analyzer)
            <x-ui.modal :id="'aynalama-'.$analyzer->uuid" :title="$analyzer->name.' · port aynalama'" wide>
                <form method="POST" action="{{ route('switches.mirroring.update', [$switch, $analyzer]) }}"
                      data-confirm="{{ $analyzer->name }} aynalaması cihaza yazılacak."
                      data-confirm-title="Cihaza yaz"
                      data-confirm-verify="{{ $switch->hostname }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$analyzer" />

                    <div class="modal__body stack">
                        <x-switching.analyzer-fields :analyzer="$analyzer" :prefix="'a'.$analyzer->uuid"
                                                     :source-options="$analyzerOptions[$analyzer->uuid]['sources']"
                                                     :output-options="$analyzerOptions[$analyzer->uuid]['outputs']"
                                                     :output-vlan-options="$analyzerOptions[$analyzer->uuid]['outputVlans']"
                                                     :vlan-options="$analyzerOptions[$analyzer->uuid]['vlans']"
                                                     :loss-priority-options="$lossPriorityOptions" />
                    </div>

                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endforeach
    @endcan
</x-layout.app>
