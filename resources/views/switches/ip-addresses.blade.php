<x-layout.app :title="$switch->hostname.' · IP adresleri'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">IP adresleri</span>
                <span class="column__count">{{ $addresses->count() }}</span>
                <span class="spacer"></span>

                @can('createSwitchIpAddresses', $switch)
                    <button type="button" class="btn btn--primary btn--sm" data-modal-open="ip-add">Adres ekle</button>
                @endcan
            </div>

            <div class="column__body">
                <div class="grid-row__head ip-row ip-row__head">
                    <span class="ip-row__address">Adres</span>
                    <span class="ip-row__interface">Arayüz</span>
                    <span class="ip-row__port">VLAN / Port</span>
                    <span class="ip-row__action"></span>
                </div>

                @forelse ($addresses as $address)
                    <div class="grid-row grid-row--static ip-row">
                        <span class="mono ip-row__address">{{ $address->cidr }}</span>
                        <span class="mono text-muted ip-row__interface truncate">{{ $address->interface_name }}</span>
                        <span class="mono text-muted ip-row__port">
                            {{ $address->vlan ? $address->vlan->label : ($address->port?->name ?: '—') }}
                        </span>

                        <span class="ip-row__action">
                            @can('updateSwitchIpAddresses', $switch)
                                @if ($editable[$address->uuid])
                                    <button type="button" class="btn btn--sm"
                                            data-modal-open="ip-{{ $address->uuid }}">Düzenle</button>
                                @else
                                    {{-- me0 gibi arayüzler panelden değiştirilemez: adresi
                                         değiştirilirse panelin cihaza erişimi kesilir. --}}
                                    <span class="text-muted">{{ $lockReason[$address->uuid] }}</span>
                                @endif
                            @endcan
                        </span>
                    </div>
                @empty
                    <x-ui.empty title="IP adresi yok"
                                text="Adresler cihazdan NETCONF ile okunur. Henüz eşitleme yapılmadıysa network:sync-netconf çalıştırın." />
                @endforelse
            </div>
        </div>
    </div>

    @can('createSwitchIpAddresses', $switch)
        <x-ui.modal id="ip-add" title="Yeni IP adresi">
            <form method="POST" action="{{ route('switches.ip-addresses.store', $switch) }}"
                  data-confirm="Adres cihaza commit edilecek. Porta adres vermek portu routed yapar ve VLAN üyeliğini düşürür."
                  data-confirm-title="Cihaza yaz">
                @csrf
                <div class="modal__body stack">
                    <x-switching.ip-fields :port-options="$portOptions" :vlan-options="$vlanOptions"
                                           :address-options="$addressOptions" />
                </div>
                <x-ui.modal-actions :submit="$canAdd ? 'Cihaza yaz' : false" />
            </form>
        </x-ui.modal>

        {{-- Değiştirilemeyen adres için pencere de çizilmez. --}}
        @foreach ($addresses->filter(fn ($address) => $editable[$address->uuid]) as $address)
            <x-ui.modal :id="'ip-'.$address->uuid" :title="$address->cidr">
                <form method="POST" action="{{ route('switches.ip-addresses.update', [$switch, $address]) }}"
                      data-confirm="Adres cihaza commit edilecek. Geri alınabilir commit kullanılır."
                      data-confirm-title="Cihaza yaz"
                      data-confirm-verify="{{ $address->cidr }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$address" />
                    <div class="modal__body stack">
                        <x-switching.ip-fields :port-options="$portOptions" :vlan-options="$vlanOptions"
                                               :address-options="$editOptions[$address->uuid]" :address="$address"
                                               :prefix="'ip-'.$address->uuid" />
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz">
                        <button type="submit" form="ip-delete-{{ $address->uuid }}" class="btn btn--danger btn--sm">Sil</button>
                        <span class="spacer"></span>
                    </x-ui.modal-actions>
                </form>

                <form method="POST" id="ip-delete-{{ $address->uuid }}" hidden
                      action="{{ route('switches.ip-addresses.destroy', [$switch, $address]) }}"
                      data-confirm="{{ $address->cidr }} adresi CİHAZDAN silinecek."
                      data-confirm-title="Adresi cihazdan sil" data-confirm-variant="danger"
                      data-confirm-verify="{{ $address->cidr }}">
                    @csrf
                    @method('DELETE')
                </form>
            </x-ui.modal>
        @endforeach
    @endcan
</x-layout.app>
