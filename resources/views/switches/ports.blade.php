<x-layout.app :title="$switch->hostname.' portları'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Portlar</span>
                <span class="column__count">{{ $summary['up'] }} / {{ $summary['total'] }} açık</span>

                <x-ui.search name="q" :value="$query"
                            placeholder="Port adı veya açıklama" label="Port filtrele" />

                <div class="column__hint" aria-hidden="true" title="↑↓ gez · → aç · ← geri · / filtrele">
                    <span class="kbd">↑↓</span>
                    <span class="kbd">→</span>
                    <span class="kbd">/</span>
                </div>
            </div>

            <div class="column__body">
                <div class="grid-row__head port-row port-row__head">
                    <span class="port-row__status"></span>
                    <span class="port-row__name">Port</span>
                    <span class="port-row__description">Açıklama</span>
                    <span class="port-row__mode">Mod</span>
                    <span class="port-row__speed">Hız</span>
                    <span class="port-row__incoming">Gelen</span>
                    <span class="port-row__outgoing">Giden</span>
                    <span class="port-row__fill">Doluluk</span>
                </div>

                @forelse ($ports as $port)
                    <button type="button" class="grid-row port-row"
                            data-port-traffic="{{ route('ajax.port.traffic', [$switch, $port['model']]) }}"
                            data-port-name="{{ $port['model']->name }}"
                            data-port-id="{{ $port['model']->uuid }}"
                            @if ($port['selected']) aria-selected="true" data-port-auto @endif>
                        <x-ui.status-dot :status="$port['model']->oper_status" :show-label="false"
                                         class="port-row__status" />
                        <span class="mono port-row__name">{{ $port['model']->name }}</span>
                        {{--
                            Açıklama yoksa masaüstünde tire ile boş olduğu
                            belirtilir; telefonda o tire her portta bir satır
                            kaplar, orada gizlenir.
                        --}}
                        <span class="truncate text-muted port-row__description"
                              @if (! $port['model']->description) data-empty @endif>{{ $port['model']->description ?: '—' }}</span>
                        <span class="port-row__mode">
                            <x-ui.badge :variant="$port['model']->port_mode->badge()">{{ $port['model']->port_mode->label() }}</x-ui.badge>
                        </span>
                        <span class="mono text-muted port-row__speed">{{ $port['link_speed_display'] }}</span>
                        <span class="mono port-row__incoming" data-label="Gelen">{{ $port['in_display'] }}</span>
                        <span class="mono port-row__outgoing" data-label="Giden">{{ $port['out_display'] }}</span>
                        <x-ui.meter :value="$port['utilisation']" metric="bandwidth"
                                    class="port-row__fill" data-label="Doluluk" />
                    </button>
                @empty
                    <x-ui.empty title="Port bulunamadı"
                                text="Bu switchte eşleşen port yok. Envanteri eşitleme dolduruyor." />
                @endforelse
            </div>
        </div>

        <aside class="detail" data-traffic-panel hidden>
            <div class="detail__head">
                <div class="detail__title mono" data-traffic-header></div>
                <p class="detail__subtitle">Trafik geçmişi</p>
            </div>

            <div class="detail__body">
                <div class="detail__section">
                    <div class="chart__ranges" role="group" aria-label="Zaman aralığı">
                        @foreach ($ranges as $key => $label)
                            <button type="button" class="btn btn--sm" data-traffic-range="{{ $key }}"
                                    aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $label }}</button>
                        @endforeach
                    </div>

                    {{--
                        Gelen ve giden ayrı grafiklerde: tek eksende küçük olan
                        büyüğün altında ezilir.
                    --}}
                    <p class="chart__state" data-traffic-status hidden></p>

                    <div class="chart-pair" data-traffic-field>
                        <figure class="chart-pair__item">
                            <figcaption class="chart-pair__label" data-traffic-title="0"></figcaption>
                            <div class="chart chart--short">
                                <canvas class="chart__canvas" data-traffic-canvas="0"></canvas>
                            </div>
                        </figure>

                        <figure class="chart-pair__item">
                            <figcaption class="chart-pair__label" data-traffic-title="1"></figcaption>
                            <div class="chart chart--short">
                                <canvas class="chart__canvas" data-traffic-canvas="1"></canvas>
                            </div>
                        </figure>
                    </div>
                </div>
            </div>

            @can('updateSwitchPorts', $switch)
                <div class="detail__foot">
                    <button type="button" class="btn btn--primary btn--block"
                            data-port-edit>Portu düzenle</button>
                </div>
            @endcan
        </aside>
    </div>

    @can('updateSwitchPorts', $switch)
        @foreach ($ports as $port)
            <x-ui.modal :id="'port-'.$port['model']->uuid" :title="$port['model']->name.' ayarları'">
                <form method="POST" action="{{ route('switches.ports.update', [$switch, $port['model']]) }}"
                      data-confirm="Bu ayarlar cihaza commit edilecek. Geri alınabilir commit kullanılır."
                      data-confirm-title="Cihaza yaz"
                      data-confirm-verify="{{ $port['model']->name }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$port['model']" />

                    <div class="modal__body stack">
                        {{--
                            Üzerinde işlem SÜRERKEN form kapatılır: ikinci bir
                            değişiklik, birincinin sonucunu görmeden hazırlanmış
                            olur ve bu ekranda "gönderilmeyen alan silinir"
                            kuralı geçerli — birincinin etkisi sessizce geri
                            alınabilirdi. Sunucu da reddediyor.
                        --}}
                        @if ($port['pending_write'])
                            <x-ui.alert variant="warning">
                                Bu portta bir işlem sürüyor: <strong>{{ $port['pending_write'] }}</strong>.
                                Bitmeden yeni bir değişiklik gönderilemez; sonuç bildirimlerde görünecek.
                            </x-ui.alert>
                        @else
                        <p class="text-sm text-muted">
                            Bu ayarlar cihaza yazılır ve sonuç cihazdan geri okunur.
                        </p>

                        <x-form.field rule="switch_ports.port:enabled" name="enabled" label="Port durumu" :id="'status-'.$port['model']->uuid"
                                      hint="Kapatılan port trafiği keser; cihazda “disable” satırı yazılır.">
                            <label class="checkbox">
                                <input type="hidden" name="enabled" value="0">
                                <input type="checkbox" name="enabled" id="status-{{ $port['model']->uuid }}"
                                       value="1" @checked($port['model']->is_enabled)>
                                <span>Port açık</span>
                            </label>
                        </x-form.field>

                        {{--
                            ae ÜYESİ portun anahtarlama ayarı OLAMAZ: üye
                            kendi birimini kaybeder ve cihaz "logical unit is
                            not allowed on aggregated links" diyor. Alanlar
                            hiç çizilmez; ayar ae arayüzünde yapılır.
                        --}}
                        @if ($port['model']->aggregate_port_id !== null)
                            <x-ui.alert variant="info" class="field--wide">
                                Bu port <span class="mono">{{ $port['aggregate'] ?? 'bir toplama arayüzünün' }}</span>
                                üyesidir. Mod, VLAN ve MTU ayarları toplama arayüzünde yapılır.
                            </x-ui.alert>
                        @else
                            {{--
                                Yapılandırılmamış portta hiçbir mod SEÇİLİ
                                GELMEZ: o bir mod değil, hiç ayarlanmamış olma
                                hâlidir ve listede karşılığı yoktur.
                            --}}
                            @if ($port['model']->port_mode === App\Enums\PortMode::Routed)
                                <x-ui.alert variant="warning" class="field--wide">
                                    Bu port yönlendirilmiş (IP adresi taşıyor). Access ya da Trunk seçip
                                    kaydederseniz <strong>IP yapılandırması silinir</strong> — adresler,
                                    bağlı firewall filtresi ve MTU dâhil.
                                    Sonradan yine <strong>Yönlendirilmiş</strong> moduna alıp adresi ve
                                    filtreyi buradan geri kurabilirsiniz.
                                </x-ui.alert>
                            @endif

                            <x-form.field rule="switch_ports.port:port_mode" name="port_mode" label="Port modu" :id="'mod-'.$port['model']->uuid"
                                          hint="Access portta tek VLAN olabilir; birden fazlası için Trunk. Yönlendirilmiş portta VLAN olmaz, IP adresi olur.">
                                {{-- Seçili mod yoksa listenin başına boş bir satır konur. --}}
                                <x-form.select name="port_mode" :id="'mod-'.$port['model']->uuid"
                                               :options="$port['selected_mode'] === null
                                                   ? ['' => '— seçilmedi —'] + $modeOptions
                                                   : $modeOptions"
                                               :selected="$port['selected_mode']" data-port-mode />
                            </x-form.field>
                        @endif

                        {{-- Hız ile auto-negotiation cihazda aynı ayardır
                             (ether-options speed); iki alan olarak sorulamaz. --}}
                        <x-form.field rule="switch_ports.port:speed_config" advanced name="speed_config" label="Hız" :id="'hiz-'.$port['model']->uuid"
                                      :hint="$port['speed_hint']" :current="$port['speed_current']">
                            <x-form.select name="speed_config" :id="'hiz-'.$port['model']->uuid"
                                           :options="$port['speed_options']"
                                           :selected="$port['model']->speed_config" data-port-speed />
                        </x-form.field>

                        {{--
                            Duplex cihazda "ether-options link-mode"tur ve
                            hızla aynı düğümde durur. Half yalnızca 10/100
                            Mbps ile geçerlidir; cihaz aksini reddediyor, o
                            yüzden seçenek diğer hızlarda kapatılır.
                        --}}
                        <x-form.field rule="switch_ports.port:duplex_config" advanced name="duplex_config" label="Duplex" :id="'duplex-'.$port['model']->uuid"
                                      hint="Otomatik bırakılırsa cihaz kendi seçer. Half yalnızca 10/100 Mbps ile."
                                      :current="$port['duplex_current']">
                            <x-form.select name="duplex_config" :id="'duplex-'.$port['model']->uuid"
                                           :options="$duplexOptions" :selected="$port['model']->duplex_config"
                                           data-port-duplex
                                           data-port-half-speeds="{{ implode(',', $halfDuplexSpeeds) }}" />
                        </x-form.field>

                        {{--
                            MTU yalnızca ae ÜYESİ OLMAYAN portlarda sorulur:
                            cihaz üyede "ae child device mtu setting ... is not
                            allowed" diyerek reddediyor; MTU o durumda ae
                            arayüzünün kendisine verilir.
                        --}}
                        @if ($port['model']->aggregate_port_id === null)
                            <x-form.field rule="switch_ports.port:mtu" advanced name="mtu" label="MTU" :id="'mtu-'.$port['model']->uuid"
                                          hint="Boş bırakılırsa cihazın varsayılanı kullanılır. Aralık: 256–9216."
                                          :current="$port['mtu_current']">
                                <x-form.input name="mtu" :id="'mtu-'.$port['model']->uuid" type="number"
                                              :value="$port['model']->mtu" min="256" max="9216" mono />
                            </x-form.field>
                        @endif

                        {{-- PoE yalnızca donanımı olan portlarda sorulur. --}}
                        @unless ($port['model']->is_poe_enabled === null)
                            <x-form.field rule="switch_ports.port:is_poe_enabled" name="is_poe_enabled" label="PoE" :id="'poe-'.$port['model']->uuid">
                                <label class="checkbox">
                                    <input type="hidden" name="is_poe_enabled" value="0">
                                    <input type="checkbox" name="is_poe_enabled" id="poe-{{ $port['model']->uuid }}"
                                           value="1" @checked($port['model']->is_poe_enabled)>
                                    <span>PoE açık</span>
                                </label>
                            </x-form.field>
                        @endunless

                        @if ($vlans->isEmpty())
                            <x-form.field rule="switch_ports.port:vlans" name="vlans" label="VLAN üyeliği" :id="'vlan-'.$port['model']->uuid">
                                <p class="text-sm text-muted">
                                    Bu switchte VLAN tanımlı değil. Önce VLAN’lar sekmesinden ekleyin.
                                </p>
                            </x-form.field>
                        @else
                            {{-- Access portta tek VLAN seçilir ve o VLAN zaten
                                 etiketsizdir; trunk portta liste ve ayrıca
                                 etiketsiz VLAN sorulur. --}}
                            {{--
                                YÖNLENDİRME modu: VLAN yerine filtre bağlaması
                                sorulur ve yazılır: yalnızca okunsaydı
                                anahtarlamaya çevrilen bir portta silinen
                                "filter input" satırı panelden bir daha
                                kurulamazdı.
                            --}}
                            <div data-port-vlan="routed" @unless ($port['model']->port_mode === App\Enums\PortMode::Routed) hidden @endunless>
                                <x-ui.alert variant="info">
                                    Yönlendirilmiş portta VLAN üyeliği olmaz; onun yerine IP adresi olur.
                                    Buradan verebilir ya da
                                    <a href="{{ route('switches.ip-addresses', $switch) }}">IP adresleri</a>
                                    ekranından yönetebilirsiniz — ikisi de aynı yere yazar.
                                </x-ui.alert>

                                {{--
                                    Adres BURADAN da verilebilir ve aynı
                                    commit'te yazılır: portu yönlendirmeye
                                    çevirip adresi ikinci bir işlemde vermek,
                                    arada portu adressiz bırakırdı.

                                    Maske sorulmaz; Adresleme'deki bloktan
                                    gelir — IP adresleri ekranıyla aynı kural,
                                    aynı kaynak.
                                --}}
                                <x-form.field rule="switch_ports.port:ip_address" name="ip_address" label="IP adresi"
                                              :id="'ip-'.$port['model']->uuid"
                                              hint="Boş bırakılırsa portun adresleri olduğu gibi kalır. Maske, adresin ait olduğu bloktan gelir.">
                                    <x-form.ip-picker name="ip_address" :id="'ip-'.$port['model']->uuid"
                                                      :options="$addressOptions" manual
                                                      :value="$port['model']->ipAddresses->first()?->address"
                                                      placeholder="10.0.0.1"
                                                      empty-text="Adresleme’de bu switche atanmış blok yok; adresi elle yazın." />
                                </x-form.field>

                                <x-form.field rule="switch_ports.port:input_filter" name="input_filter" label="Giriş filtresi"
                                              :id="'gfiltre-'.$port['model']->uuid"
                                              hint="Porta GELEN trafiğe uygulanır. Yalnızca inet ailesindeki filtreler bağlanabilir.">
                                    <x-form.select name="input_filter" :id="'gfiltre-'.$port['model']->uuid"
                                                   :options="$filterOptions"
                                                   :selected="$port['input_filter'] ?? null" />
                                </x-form.field>

                                <x-form.field rule="switch_ports.port:output_filter" name="output_filter" label="Çıkış filtresi"
                                              :id="'cfiltre-'.$port['model']->uuid"
                                              hint="Porttan ÇIKAN trafiğe uygulanır.">
                                    <x-form.select name="output_filter" :id="'cfiltre-'.$port['model']->uuid"
                                                   :options="$filterOptions"
                                                   :selected="$port['output_filter'] ?? null" />
                                </x-form.field>
                            </div>

                            <div data-port-vlan="access" @unless ($port['model']->is_access) hidden @endunless>
                                <x-form.field rule="switch_ports.port:vlans" name="vlans" label="VLAN" :id="'vlan-tek-'.$port['model']->uuid"
                                              hint="Boş bırakılırsa port hiçbir VLAN’a ait olmaz.">
                                    <x-form.select name="vlans[]" :id="'vlan-tek-'.$port['model']->uuid"
                                                   :options="$vlanOptions"
                                                   :selected="$port['model']->vlans->first()?->uuid" />
                                </x-form.field>
                            </div>

                            <div data-port-vlan="trunk" @if ($port['model']->is_access) hidden @endif>
                                <x-form.field rule="switch_ports.port:vlans" name="vlans" label="VLAN üyeliği" :id="'vlan-'.$port['model']->uuid"
                                              hint="Hiçbiri seçilmezse port VLAN’sız kalır.">
                                    @foreach ($vlans as $vlan)
                                        <label class="checkbox">
                                            <input type="checkbox" name="vlans[]" value="{{ $vlan->uuid }}"
                                                   @checked($port['model']->vlans->contains($vlan))>
                                            <span class="mono">{{ $vlan->vlan_tag ?? '—' }}</span>
                                            <span class="text-sm text-muted">{{ $vlan->name }}</span>
                                        </label>
                                    @endforeach
                                </x-form.field>

                                <x-form.field rule="switch_ports.port:untagged_vlan" name="untagged_vlan" label="Etiketsiz VLAN"
                                              :id="'untagged-'.$port['model']->uuid"
                                              hint="Seçilen VLAN’lardan biri olmalı; zorunlu değil.">
                                    <x-form.select name="untagged_vlan" :id="'untagged-'.$port['model']->uuid"
                                                   :options="$untaggedOptions"
                                                   :selected="$port['model']->vlans->firstWhere('pivot.is_untagged', true)?->uuid" />
                                </x-form.field>
                            </div>
                        @endif


                        <x-form.field rule="switch_ports.port:description" name="description" label="Açıklama" :id="'aciklama-'.$port['model']->uuid"
                                      hint="Cihazdaki interface description alanı.">
                            <x-form.input name="description" :id="'aciklama-'.$port['model']->uuid"
                                          :value="$port['model']->description" />
                        </x-form.field>
                        @endif
                    </div>

                    {{-- İşlem sürerken "cihaza yaz" sunulmaz; sunucu da reddeder. --}}
                    @if ($port['pending_write'])
                        <x-ui.modal-actions :submit="false" cancel="Kapat" />
                    @else
                        <x-ui.modal-actions submit="Cihaza yaz" />
                    @endif
                </form>
            </x-ui.modal>
        @endforeach
    @endcan
</x-layout.app>
