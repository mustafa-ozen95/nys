<x-layout.app :title="$switch->hostname.' · VLAN’lar'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">VLAN’lar</span>
                <span class="column__count">{{ $vlans->count() }}</span>
                <span class="spacer"></span>

                @can('createSwitchVlans', $switch)
                    <button type="button" class="btn btn--primary btn--sm" data-modal-open="vlan-ekle">
                        VLAN ekle
                    </button>
                @endcan
            </div>

            <div class="column__body">
                <div class="grid-row__head vlan-row vlan-row__head">
                    <span class="vlan-row__etiket">Etiket</span>
                    <span class="vlan-row__ad">Ad</span>
                    <span class="vlan-row__l3">L3 arayüzü</span>
                    <span class="vlan-row__adet">Adet</span>
                    <span class="vlan-row__port">Portlar</span>
                    <span class="vlan-row__eylem"></span>
                </div>

                @forelse ($vlans as $vlan)
                    <div class="grid-row grid-row--static vlan-row">
                        <span class="mono vlan-row__etiket">{{ $vlan->vlan_tag ?? '—' }}</span>
                        <span class="vlan-row__ad truncate">{{ $vlan->name }}</span>
                        <span class="mono text-muted vlan-row__l3">{{ $vlan->l3_interface ?: '—' }}</span>
                        <span class="mono text-muted vlan-row__adet">{{ $vlan->ports_count }}</span>
                        <span class="mono text-muted truncate vlan-row__port">{{ $vlan->port_list ?: '—' }}</span>

                        <span class="vlan-row__eylem">
                            @can('updateSwitchVlans', $switch)
                                <button type="button" class="btn btn--sm"
                                        data-modal-open="vlan-{{ $vlan->uuid }}">Düzenle</button>
                            @endcan
                        </span>
                    </div>
                @empty
                    <x-ui.empty title="VLAN yok"
                                text="Liste cihazdan NETCONF ile okunur. Henüz eşitleme yapılmadıysa network:sync-netconf çalıştırın." />
                @endforelse
            </div>
        </div>
    </div>

    @can('createSwitchVlans', $switch)
        <x-ui.modal id="vlan-ekle" title="Yeni VLAN">
            <form method="POST" action="{{ route('switches.vlans.store', $switch) }}"
                  data-confirm="VLAN cihaza commit edilecek. Geri alınabilir commit kullanılır."
                  data-confirm-title="Cihaza yaz">
                @csrf

                <div class="modal__body stack">
                    <x-vlan.fields :ports="$ports" />
                </div>

                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>
    @endcan

    @foreach ($vlans as $vlan)
        @can('updateSwitchVlans', $switch)
            <x-ui.modal :id="'vlan-'.$vlan->uuid" :title="'VLAN '.$vlan->name">
                <form method="POST" action="{{ route('switches.vlans.update', [$switch, $vlan]) }}"
                      data-confirm="Değişiklik cihaza commit edilecek. Geri alınabilir commit kullanılır."
                      data-confirm-title="Cihaza yaz"
                      data-confirm-verify="{{ $vlan->name }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$vlan" />

                    <div class="modal__body stack">
                        <x-vlan.fields :vlan="$vlan" :ports="$ports" :prefix="'vlan-'.$vlan->uuid" />
                    </div>

                    <x-ui.modal-actions submit="Cihaza yaz">
                        {{-- Silme ayrı bir form; iç içe form geçersizdir. --}}
                        <button type="submit" form="vlan-sil-{{ $vlan->uuid }}" class="btn btn--danger btn--sm">Sil</button>
                        <span class="spacer"></span>
                    </x-ui.modal-actions>
                </form>

                <form method="POST" id="vlan-sil-{{ $vlan->uuid }}" hidden
                      action="{{ route('switches.vlans.destroy', [$switch, $vlan]) }}"
                      data-confirm="VLAN {{ $vlan->name }} CİHAZDAN silinecek; üye portlardan da çıkarılır."
                      data-confirm-title="VLAN’ı sil"
                      data-confirm-verify="{{ $vlan->name }}"
                      data-confirm-variant="danger">
                    @csrf
                    @method('DELETE')
                </form>
            </x-ui.modal>
        @endcan
    @endforeach
</x-layout.app>
