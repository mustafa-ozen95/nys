<x-layout.app :title="$switch->hostname.' · VoIP VLAN'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">VoIP VLAN</span>
                <span class="column__count">{{ $voipInterfaces->count() }}</span>
                <span class="spacer"></span>

                @can('createSwitchVoip', $switch)
                    @if ($hasVlans && $interfaceOptions)
                        <button type="button" class="btn btn--primary btn--sm" data-modal-open="voip-ekle">Arayüz ekle</button>
                    @endif
                @endcan
            </div>

            <div class="column__body">
                <x-ui.flash />

                {{--
                    Ayarın ne yaptığı ekranda yazar: IP telefon ile ona bağlı
                    bilgisayar TEK kabloyu paylaşır ve ancak biri etiketli
                    diğeri etiketsiz gittiğinde ayrışırlar.
                --}}
                <x-ui.panel title="Ses VLAN’ı"
                            subtitle="IP telefon ile ona bağlı bilgisayar tek kabloyu paylaşır: telefon ses VLAN’ında etiketli, bilgisayar portun kendi VLAN’ında etiketsiz gider.">
                    @unless ($hasVlans)
                        <x-ui.alert variant="warning">
                            Bu switchte hiç VLAN tanımlı değil. Cihazda VLAN’ın kimliği adıdır ve tanımsız bir ada
                            atıf commit’i düşürür; önce VLAN ekranından ses VLAN’ı tanımlanmalı.
                        </x-ui.alert>
                    @endunless

                    <x-ui.alert variant="info">
                        Cihaz yalnızca <strong>ethernet-switching ailesindeki</strong> portlara ses VLAN’ı tanımlar;
                        IP adresi verilmiş (routed) ve hiç yapılandırılmamış portlar listede yoktur.
                        <span class="mono">access-ports</span> bütün access portlarını kapsayan cihaz adıdır.
                    </x-ui.alert>
                </x-ui.panel>

                <div class="grid-row__head ayar-row">
                    <span>Arayüz</span>
                    <span>Ses VLAN’ı</span>
                    <span>İletim sınıfı</span>
                    <span></span>
                </div>

                @forelse ($voipInterfaces as $voipInterface)
                    <div class="grid-row grid-row--static ayar-row">
                        <span class="mono">{{ $voipInterface->interface_name }}</span>
                        <span>
                            @if ($voipInterface->vlan_name)
                                <x-ui.badge variant="cyan">{{ $voipInterface->vlan_name }}</x-ui.badge>
                            @else
                                <span class="text-muted">—</span>
                            @endif

                            @if ($voipInterface->is_virtual)
                                <x-ui.badge variant="gray" title="Cihazın kabul ettiği sanal arayüz adı">toplu</x-ui.badge>
                            @endif
                        </span>
                        <span class="mono text-muted">
                            {{ $voipInterface->forwarding_class ?: '—' }}

                            @if ($voipInterface->preserved_lines)
                                <x-ui.badge variant="cyan"
                                            title="Panelde gösterilmeyen ama korunan satırlar: {{ implode(', ', $voipInterface->preserved_lines) }}">{{ count($voipInterface->preserved_lines) }} korunan satır</x-ui.badge>
                            @endif
                        </span>
                        <span class="text-right">
                            @can('updateSwitchVoip', $switch)
                                <button type="button" class="btn btn--sm"
                                        data-modal-open="voip-{{ $voipInterface->uuid }}">Düzenle</button>
                            @endcan
                        </span>
                    </div>
                @empty
                    <x-ui.empty title="Ses VLAN’ı tanımlı değil"
                                text="Telefonlar portun kendi VLAN’ında etiketsiz çalışıyor. Telefon ile bilgisayarı aynı kabloda ayırmak için bir ses VLAN’ı tanımlanır." />
                @endforelse
            </div>
        </div>
    </div>

    @can('createSwitchVoip', $switch)
        @if ($hasVlans && $interfaceOptions)
            <x-ui.modal id="voip-ekle" title="Ses VLAN’ı tanımla">
                <form method="POST" action="{{ route('switches.voip.store', $switch) }}"
                      data-confirm="Ses VLAN’ı cihaza yazılacak. Yanlış VLAN seçilirse o porttaki telefonlar sessizce kayıt olamaz."
                      data-confirm-title="Cihaza yaz" data-confirm-verify="{{ $switch->hostname }}">
                    @csrf
                    <div class="modal__body stack">
                        <x-form.field name="interface_name" label="Arayüz" id="voip-arayuz"
                                      hint="Junos arayüzü birimiyle yazar (ge-0/0/5.0). Yalnızca anahtarlama ailesindeki portlar listelenir.">
                            <x-form.select name="interface_name" id="voip-arayuz" :options="$interfaceOptions" />
                        </x-form.field>

                        <x-switching.voip-fields prefix="voip-yeni" :vlan-options="$vlanOptions"
                                                 :forwarding-classes="$forwardingClasses" />
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endif
    @endcan

    @can('updateSwitchVoip', $switch)
        @foreach ($voipInterfaces as $voipInterface)
            <x-ui.modal :id="'voip-'.$voipInterface->uuid" :title="$voipInterface->interface_name.' · ses VLAN’ı'">
                <form method="POST" action="{{ route('switches.voip.update', [$switch, $voipInterface]) }}"
                      data-confirm="{{ $voipInterface->interface_name }} arayüzünün ses VLAN’ı cihaza yazılacak."
                      data-confirm-title="Cihaza yaz" data-confirm-verify="{{ $switch->hostname }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$voipInterface" />
                    <div class="modal__body stack">
                        {{--
                            Arayüz adı cihazda KİMLİKTİR ve değiştirilemez;
                            formda sorulmaz, sunucu da değişmiş değeri
                            açıkça reddeder.
                        --}}
                        <x-switching.voip-fields :voip-interface="$voipInterface"
                                                 :vlan-options="$vlanOptions"
                                                 :forwarding-classes="$forwardingClasses"
                                                 :prefix="'v'.$voipInterface->uuid" />
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz">
                        @can('deleteSwitchVoip', $switch)
                            <button type="submit" form="voip-sil-{{ $voipInterface->uuid }}"
                                    class="btn btn--danger btn--sm">Sil</button>
                            <span class="spacer"></span>
                        @endcan
                    </x-ui.modal-actions>
                </form>

                @can('deleteSwitchVoip', $switch)
                    <form method="POST" id="voip-sil-{{ $voipInterface->uuid }}" hidden
                          action="{{ route('switches.voip.destroy', [$switch, $voipInterface]) }}"
                          data-confirm="{{ $voipInterface->interface_name }} arayüzünün ses VLAN’ı CİHAZDAN kaldırılacak; telefon trafiği etiketsiz VLAN’a düşer."
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
