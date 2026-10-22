<x-layout.app :title="$switch->hostname.' · Bağlantı toplama'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Bağlantı toplama</span>
                <span class="column__count">{{ $aggregates->count() }}</span>
                <span class="spacer"></span>

                @can('updateSwitchAggregation', $switch)
                    <button type="button" class="btn btn--sm" data-modal-open="ae-limit">ae limiti</button>
                @endcan
            </div>

            <div class="column__body column__body--padded">
                <div class="stack stack--loose">
                    {{--
                        ae arayüzleri panelde YARATILMAZ. Limit kaç ise chassis
                        o kadarını oluşturur; yeni bir LAG için önce limit
                        artırılır.
                    --}}
                    @if ($deviceCount === null)
                        <x-ui.alert variant="warning">
                            Bu switchte ae limiti tanımlı değil, bu yüzden hiç toplama arayüzü yok.
                            Limit verildiğinde cihaz o sayı kadar arayüz (ae0, ae1…) oluşturur.
                        </x-ui.alert>
                    @else
                        <x-ui.alert variant="info">
                            ae limiti <strong>{{ $deviceCount }}</strong>: cihazda ae0–ae{{ $deviceCount - 1 }} var.
                            Limiti düşürmek üstteki arayüzleri yapılandırmaları dururken siler.
                        </x-ui.alert>
                    @endif

                    @forelse ($aggregates as $aggregate)
                        <x-ui.panel :title="$aggregate->name"
                                    :subtitle="$aggregate->members->count().' üye · '.$aggregate->port_mode->label()">
                            <x-slot:actions>
                                @can('updateSwitchAggregation', $switch)
                                    <button type="button" class="btn btn--sm"
                                            data-modal-open="ae-{{ $aggregate->uuid }}">Düzenle</button>

                                    @if ($aggregate->members->isNotEmpty() || $aggregate->lacp_mode || $aggregate->description)
                                        <x-ui.delete-form :action="route('switches.aggregation.destroy', [$switch, $aggregate])"
                                                          confirm="{{ $aggregate->name }} yapılandırması ve üyelikleri silinecek. Üye portlar VLAN’sız kalır."
                                                          title="Yapılandırmayı sil"
                                                          verify="{{ $aggregate->name }}" />
                                    @endif
                                @endcan
                            </x-slot:actions>

                            <x-ui.facts :rows="[
                                'Durum' => $aggregate->oper_status->label(),
                                'Açıklama' => $aggregate->description,
                                'LACP' => $aggregate->lacp_mode?->label() ?? 'Yok (statik)',
                                'LACP periyodu' => $aggregate->lacp_periodic?->label(),
                                'En az bağlantı' => $aggregate->minimum_links,
                                'Bağlantı hızı' => $aggregate->link_speed ? strtoupper($aggregate->link_speed) : null,
                                'Üye portlar' => $aggregate->members->pluck('name')->join(', ') ?: null,
                                'VLAN’lar' => $aggregate->vlans->pluck('name')->join(', ') ?: null,
                            ]" />
                        </x-ui.panel>
                    @empty
                        <x-ui.empty title="Toplama arayüzü yok"
                                    text="ae limiti verildiğinde cihaz arayüzleri kendisi oluşturur." />
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @can('updateSwitchAggregation', $switch)
        <x-ui.modal id="ae-limit" title="ae limiti">
            <form method="POST" action="{{ route('switches.aggregation.device-count', $switch) }}"
                  data-confirm="Limiti DÜŞÜRMEK, üstteki ae arayüzlerini yapılandırmaları dururken cihazdan siler. Bu işlem cihaza commit atar."
                  data-confirm-title="Cihaza yaz"
                  data-confirm-verify="{{ $switch->hostname }}">
                @csrf
                @method('PUT')

                <div class="modal__body">
                    <x-form.field name="device_count" label="Kaç ae arayüzü olsun?" id="ae-number"
                                  hint="Cihaz bu sayı kadar arayüz oluşturur: 4 verilirse ae0–ae3. Aralık 1–111.">
                        <x-form.input name="device_count" id="ae-number" type="number"
                                      :value="$deviceCount" min="1" max="111" required />
                    </x-form.field>
                </div>

                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>

        @foreach ($aggregates as $aggregate)
            <x-ui.modal :id="'ae-'.$aggregate->uuid" :title="$aggregate->name.' ayarları'" wide>
                <form method="POST" action="{{ route('switches.aggregation.update', [$switch, $aggregate]) }}"
                      data-ae-form
                      data-confirm="Bu işlem cihaza commit atar. Üye yapılan portlar kendi VLAN’larını kaybeder."
                      data-confirm-title="Cihaza yaz"
                      data-confirm-verify="{{ $aggregate->name }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$aggregate" />

                    <x-aggregation.fields :aggregate="$aggregate" :member-options="$memberOptions"
                                          :vlan-options="$vlanOptions" :untagged-options="$untaggedOptions"
                                          :mode-options="$modeOptions" :lacp-options="$lacpOptions"
                                          :periodic-options="$periodicOptions"
                                          :link-speed-options="$linkSpeedOptions" :max-members="$maxMembers"
                                          :prefix="'ae'.$aggregate->uuid" />

                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endforeach
    @endcan
</x-layout.app>
