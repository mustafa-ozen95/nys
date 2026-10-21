<x-layout.app :title="$switch->hostname.' · Spanning tree'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Spanning tree</span>
                <span class="column__count">{{ $interfaces->count() }}</span>
                <span class="spacer"></span>

                @can('createSwitchRstp', $switch)
                    <button type="button" class="btn btn--primary btn--sm" data-modal-open="rstp-ekle">Arayüz ekle</button>
                @endcan
            </div>

            <div class="column__body">
                <x-ui.flash />

                {{--
                    Köprü ayarları. Önceliği düşürmek bu switchi kök köprü
                    yapar ve topoloji yeniden yakınsar — birkaç saniye
                    kesinti demektir. Onay penceresi switch adı yazdırır.
                --}}
                <x-ui.panel title="Köprü"
                            subtitle="Kök köprü seçimi ve BPDU koruması. Değişiklik topolojiyi yeniden yakınsatır.">
                    @unless ($switch->rstp_configured)
                        <x-ui.alert variant="info">
                            Cihazda spanning tree yapılandırması yok; Junos kendi varsayılanını uyguluyor.
                            Aşağıdan yazıldığında açıkça tanımlanır.
                        </x-ui.alert>
                    @endunless

                    @can('updateSwitchRstp', $switch)
                        <form method="POST" action="{{ route('switches.rstp.bridge', $switch) }}"
                              data-confirm="Spanning tree köprü ayarları cihaza yazılacak. Öncelik değişirse kök köprü yeniden seçilir ve topoloji birkaç saniye yeniden yakınsar."
                              data-confirm-title="Cihaza yaz"
                              data-confirm-verify="{{ $switch->hostname }}">
                            @csrf
                            @method('PUT')

                            <div class="form-grid">
                                <x-form.field kural="switch_rstp.genel:enabled" name="enabled" label="Spanning tree" id="rstp-acik"
                                              hint="Kapatmak döngü korumasını KALDIRIR; yedekli bağlantı varsa ağ döngüye girer.">
                                    <label class="checkbox">
                                        <input type="hidden" name="enabled" value="0">
                                        <input type="checkbox" name="enabled" id="rstp-acik"
                                               value="1" @checked($switch->rstp_enabled)>
                                        <span>Açık</span>
                                    </label>
                                </x-form.field>

                                <x-form.field kural="switch_rstp.genel:bridge_priority" name="bridge_priority" label="Köprü önceliği" id="rstp-oncelik"
                                              hint="Düşük değer kök köprü olma şansını artırır. Junos yalnızca 4096’nın katlarını kabul eder.">
                                    <x-form.select name="bridge_priority" id="rstp-oncelik"
                                                   :options="$priorityOptions"
                                                   :selected="$switch->rstp_bridge_priority" />
                                </x-form.field>

                                <x-form.field kural="switch_rstp.genel:bpdu_block_on_edge" name="bpdu_block_on_edge" label="BPDU koruması" id="rstp-bpdu"
                                              class="field--wide"
                                              hint="Uç nokta portuna BPDU gelirse port kapatılır: yanlışlıkla takılan switch ağı bozamaz.">
                                    <label class="checkbox">
                                        <input type="hidden" name="bpdu_block_on_edge" value="0">
                                        <input type="checkbox" name="bpdu_block_on_edge" id="rstp-bpdu"
                                               value="1" @checked($switch->rstp_bpdu_block_on_edge)>
                                        <span>Uç nokta portlarında BPDU’yu engelle</span>
                                    </label>
                                </x-form.field>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary btn--sm">Cihaza yaz</button>
                            </div>
                        </form>
                    @endcan
                </x-ui.panel>

                {{--
                    FIRTINA DENETİMİ. Spanning tree döngüye karşı korur;
                    fırtına denetimi ise bir portun ağı yayın trafiğiyle
                    boğmasına karşı. İkisi de port korumasıdır ve aynı yetkiye
                    bağlıdır: yanlış bir eşik portu kullanılamaz yapar.

                    Ayarın cihazdaki YERİ şema ailesine bağlıdır ve ikisi
                    birbirinin yerine geçmez (test1'de ölçüldü); panel doğru
                    yeri kendisi seçer.
                --}}
                <x-ui.panel title="Fırtına denetimi"
                            subtitle="Yayın fırtınası bir anahtarı dakikalar içinde kullanılamaz hâle getirir. Eşik aşılınca fazla trafik düşürülür.">
                    @can('updateSwitchRstp', $switch)
                        <form method="POST" action="{{ route('switches.rstp.storm.shutdown', $switch) }}"
                              data-confirm="Eşik aşıldığında portun KAPATILMASI ayarı cihaza yazılacak. Açıkken fırtına üreten port hizmet dışı kalır."
                              data-confirm-title="Cihaza yaz"
                              data-confirm-verify="{{ $switch->hostname }}">
                            @csrf
                            @method('PUT')

                            <x-form.field kural="switch_rstp.genel:storm_control_shutdown" name="storm_control_shutdown" label="Eşik aşılınca portu kapat"
                                          id="firtina-kapat"
                                          hint="Kapalıyken cihaz yalnızca fazla trafiği düşürür; açıkken portu hizmet dışı bırakır.">
                                <label class="checkbox">
                                    <input type="checkbox" name="storm_control_shutdown" value="1"
                                           @checked($switch->storm_control_shutdown)>
                                    <span>Portu kapat (action-shutdown)</span>
                                </label>
                            </x-form.field>

                            <x-ui.modal-actions submit="Cihaza yaz" />
                        </form>
                    @endcan

                    @forelse ($stormControls as $storm)
                        <div class="row row--between prefix-entry">
                            <span class="mono">{{ $storm->interface_name }}</span>
                            <span class="text-sm">{{ $storm->threshold_label }}</span>

                            <span>
                                @foreach ($storm->exemptions as $muaf)
                                    <x-ui.badge variant="gray" title="Denetim dışında">{{ $muaf }}</x-ui.badge>
                                @endforeach

                                @if ($storm->preserved_lines)
                                    <x-ui.badge variant="cyan"
                                                title="Panelde gösterilmeyen ama korunan satırlar: {{ implode(', ', $storm->preserved_lines) }}">{{ count($storm->preserved_lines) }} korunan satır</x-ui.badge>
                                @endif
                            </span>

                            @can('updateSwitchRstp', $switch)
                                <button type="button" class="btn btn--sm"
                                        data-modal-open="firtina-{{ $storm->uuid }}">Düzenle</button>
                            @endcan
                        </div>
                    @empty
                        <x-ui.empty title="Fırtına denetimi tanımlı değil"
                                    :text="$allKeyword
                                        ? 'Cihaz yayın trafiğini sınırlamıyor. “Bütün portlar (all)” için bir eşik vermek en yaygın başlangıçtır.'
                                        : 'Cihaz yayın trafiğini sınırlamıyor. Uç nokta portlarına %80 eşik vermek en yaygın başlangıçtır.'" />
                    @endforelse

                    @can('createSwitchRstp', $switch)
                        <button type="button" class="btn btn--sm" data-modal-open="firtina-ekle">Fırtına denetimi ekle</button>
                    @endcan
                </x-ui.panel>

                <div class="grid-row__head ayar-row">
                    <span>Arayüz</span>
                    <span>Uç nokta</span>
                    <span>Maliyet / öncelik</span>
                    <span></span>
                </div>

                @forelse ($interfaces as $interface)
                    <div class="grid-row grid-row--static ayar-row">
                        <span class="mono">{{ $interface->interface_name }}</span>
                        <span>
                            @if ($interface->edge)
                                <x-ui.badge variant="orange">Uç nokta</x-ui.badge>
                            @endif
                            @if ($interface->no_root_port)
                                <x-ui.badge variant="purple">Kök olamaz</x-ui.badge>
                            @endif
                            @unless ($interface->edge || $interface->no_root_port)
                                <span class="text-muted">—</span>
                            @endunless
                        </span>
                        <span class="mono text-muted">
                            {{ $interface->cost ?: '—' }} / {{ $interface->priority ?? '—' }}
                        </span>
                        <span class="text-right">
                            @can('updateSwitchRstp', $switch)
                                <button type="button" class="btn btn--sm"
                                        data-modal-open="rstp-{{ $interface->uuid }}">Düzenle</button>
                            @endcan
                        </span>
                    </div>
                @empty
                    <x-ui.empty title="Arayüz ayarı yok"
                                text="Bütün portlar köprü ayarlarını kullanıyor. Sunucu bağlı portları uç nokta yapmak gecikmeyi kaldırır." />
                @endforelse
            </div>
        </div>
    </div>

    @can('createSwitchRstp', $switch)
        <x-ui.modal id="rstp-ekle" title="Spanning tree arayüzü">
            <form method="POST" action="{{ route('switches.rstp.store', $switch) }}"
                  data-confirm="Arayüzün spanning tree ayarı cihaza yazılacak. “Uç nokta” switch bağlı bir porta verilirse o portta döngü oluşur."
                  data-confirm-title="Cihaza yaz"
                  data-confirm-verify="{{ $switch->hostname }}">
                @csrf
                <div class="modal__body stack">
                    <x-bgp.rstp-fields :interface-options="$interfaceOptions" />
                </div>
                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>
    @endcan

    @can('updateSwitchRstp', $switch)
        @foreach ($interfaces as $interface)
            <x-ui.modal :id="'rstp-'.$interface->uuid" :title="$interface->interface_name.' · spanning tree'">
                <form method="POST" action="{{ route('switches.rstp.update', [$switch, $interface]) }}"
                      data-confirm="Arayüzün spanning tree ayarı cihaza yazılacak."
                      data-confirm-title="Cihaza yaz"
                      data-confirm-verify="{{ $switch->hostname }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$interface" />
                    <div class="modal__body stack">
                        <x-bgp.rstp-fields :interface="$interface" :interface-options="$interfaceOptions"
                                           :prefix="'r'.$interface->uuid" />
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz">
                        @can('deleteSwitchRstp', $switch)
                            <button type="submit" form="rstp-sil-{{ $interface->uuid }}"
                                    class="btn btn--danger btn--sm">Sil</button>
                            <span class="spacer"></span>
                        @endcan
                    </x-ui.modal-actions>
                </form>

                @can('deleteSwitchRstp', $switch)
                    <form method="POST" id="rstp-sil-{{ $interface->uuid }}" hidden
                          action="{{ route('switches.rstp.destroy', [$switch, $interface]) }}"
                          data-confirm="{{ $interface->interface_name }} arayüzünün spanning tree ayarı CİHAZDAN silinecek."
                          data-confirm-title="Ayarı sil" data-confirm-variant="danger"
                          data-confirm-verify="{{ $switch->hostname }}">
                        @csrf
                        @method('DELETE')
                    </form>
                @endcan
            </x-ui.modal>
        @endforeach
    @endcan
    @can('createSwitchRstp', $switch)
        <x-ui.modal id="firtina-ekle" title="Fırtına denetimi ekle">
            <form method="POST" action="{{ route('switches.rstp.storm.store', $switch) }}"
                  data-confirm="Fırtına denetimi cihaza yazılacak. Eşik çok düşükse normal trafik de düşürülür."
                  data-confirm-title="Cihaza yaz" data-confirm-verify="{{ $switch->hostname }}">
                @csrf
                <div class="modal__body stack">
                    <x-form.field kural="switch_rstp.genel:interface_name" name="interface_name" label="Arayüz" id="firtina-arayuz"
                                  :hint="$allKeyword
                                      ? '“all” bütün anahtarlama portlarını kapsar.'
                                      : 'Bu Junos ailesi “all” kısayolunu kabul etmez; port tek tek seçilir.'">
                        <x-form.select name="interface_name" id="firtina-arayuz" :options="$stormInterfaceOptions" />
                    </x-form.field>

                    <x-switching.storm-fields prefix="firtina-yeni" />
                </div>
                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>
    @endcan

    @can('updateSwitchRstp', $switch)
        @foreach ($stormControls as $storm)
            <x-ui.modal :id="'firtina-'.$storm->uuid" :title="$storm->interface_name.' · fırtına denetimi'">
                <form method="POST" action="{{ route('switches.rstp.storm.update', [$switch, $storm]) }}"
                      data-confirm="{{ $storm->interface_name }} fırtına denetimi cihaza yazılacak."
                      data-confirm-title="Cihaza yaz" data-confirm-verify="{{ $switch->hostname }}">
                    @csrf
                    @method('PUT')
                    <div class="modal__body stack">
                        <x-switching.storm-fields :control="$storm" :prefix="'f'.$storm->uuid" />
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz">
                        @can('deleteSwitchRstp', $switch)
                            <button type="submit" form="firtina-sil-{{ $storm->uuid }}"
                                    class="btn btn--danger btn--sm">Sil</button>
                            <span class="spacer"></span>
                        @endcan
                    </x-ui.modal-actions>
                </form>

                @can('deleteSwitchRstp', $switch)
                    <form method="POST" id="firtina-sil-{{ $storm->uuid }}" hidden
                          action="{{ route('switches.rstp.storm.destroy', [$switch, $storm]) }}"
                          data-confirm="{{ $storm->interface_name }} fırtına denetimi CİHAZDAN kaldırılacak; yayın trafiği sınırsız kalır."
                          data-confirm-title="Ayarı sil" data-confirm-variant="danger"
                          data-confirm-verify="{{ $switch->hostname }}">
                        @csrf
                        @method('DELETE')
                    </form>
                @endcan
            </x-ui.modal>
        @endforeach
    @endcan
</x-layout.app>
