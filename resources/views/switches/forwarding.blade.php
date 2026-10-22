<x-layout.app :title="$switch->hostname.' · Filtre yönlendirme'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Filtre yönlendirme</span>
                <span class="column__count">{{ $instances->count() + $groups->count() }}</span>
            </div>

            <div class="column__body column__body--padded">
                <div class="stack stack--loose">
                    {{--
                        FBF üç parçadan oluşur ve üçü olmadan çalışmaz. En çok
                        atlanan parça RIB grubunun BAĞLANMASI: arayüz yolları
                        yalnızca inet.0'dadır, kopyalanmazsa hedef tablo kendi
                        arayüzlerine bile ulaşamaz ve trafik sessizce düşer —
                        cihaz hata VERMEZ. Eksikler denetleyicide hesaplanır.
                    --}}
                    @if ($readiness !== [])
                        <x-ui.alert variant="warning">
                            <strong>Filtre yönlendirme henüz kurulu değil.</strong>
                            <ul class="stack">
                                @foreach ($readiness as $eksik)
                                    <li>{{ $eksik }}</li>
                                @endforeach
                            </ul>
                        </x-ui.alert>
                    @endif

                    <x-ui.panel title="Yönlendirme tabloları"
                                subtitle="Firewall terimi trafiği bu tablolara atar; tablo kendi yolunu taşır.">
                        <x-slot:actions>
                            @can('createSwitchForwarding', $switch)
                                <button type="button" class="btn btn--primary btn--sm"
                                        data-modal-open="table-add">Tablo ekle</button>
                            @endcan
                        </x-slot:actions>

                        @forelse ($instances as $instance)
                            <div class="grid-row setting-row">
                                <span class="mono">{{ $instance->name }}</span>
                                <x-ui.badge :variant="$instance->instance_type?->badge()">
                                    {{ $instance->instance_type?->label() ?? '—' }}
                                </x-ui.badge>
                                <span class="text-muted">{{ $instance->description ?: '—' }}</span>

                                <div class="row-actions">
                                    @can('updateSwitchForwarding', $switch)
                                        <button type="button" class="btn btn--sm"
                                                data-modal-open="tablo-{{ $instance->uuid }}">Düzenle</button>
                                    @endcan

                                    @can('deleteSwitchForwarding', $switch)
                                        <x-ui.delete-form :action="route('switches.forwarding.instances.destroy', [$switch, $instance])"
                                                          confirm="{{ $instance->name }} tablosu cihazdan silinecek. Bu tabloya atıf yapan firewall terimleri çalışmaz hâle gelir."
                                                          title="Tabloyu sil"
                                                          :verify="$instance->name" />
                                    @endcan
                                </div>
                            </div>
                        @empty
                            <x-ui.empty title="Yönlendirme tablosu yok"
                                        text="Filtre tabanlı yönlendirme için önce trafiğin atılacağı bir tablo gerekir." />
                        @endforelse
                    </x-ui.panel>

                    <x-ui.panel title="RIB grupları"
                                subtitle="Bir tablodaki yolları başka tablolara kopyalar. Sıra anlamlıdır: ilk tablo birincildir.">
                        <x-slot:actions>
                            @can('createSwitchForwarding', $switch)
                                <button type="button" class="btn btn--primary btn--sm"
                                        data-modal-open="group-add">Grup ekle</button>
                            @endcan
                        </x-slot:actions>

                        @forelse ($groups as $group)
                            <div class="grid-row setting-row">
                                <span class="mono">{{ $group->name }}</span>
                                <span class="mono text-muted">{{ implode(' → ', $group->import_ribs ?? []) }}</span>
                                <span>
                                    @if ($boundGroup === $group->name)
                                        <x-ui.badge variant="green">Arayüz yollarına bağlı</x-ui.badge>
                                    @endif
                                </span>

                                <div class="row-actions">
                                    @can('updateSwitchForwarding', $switch)
                                        <button type="button" class="btn btn--sm"
                                                data-modal-open="grup-{{ $group->uuid }}">Düzenle</button>
                                    @endcan

                                    @can('deleteSwitchForwarding', $switch)
                                        <x-ui.delete-form :action="route('switches.forwarding.groups.destroy', [$switch, $group])"
                                                          confirm="{{ $group->name }} grubu cihazdan silinecek. Arayüz yolları kopyalanmayı bırakır."
                                                          title="Grubu sil"
                                                          :verify="$group->name" />
                                    @endcan
                                </div>
                            </div>
                        @empty
                            <x-ui.empty title="RIB grubu yok"
                                        text="Arayüz yolları hedef tabloya kopyalanmadan trafik sessizce düşer." />
                        @endforelse
                    </x-ui.panel>

                    @can('updateSwitchForwarding', $switch)
                        <x-ui.panel title="Arayüz yolları bağlaması"
                                    subtitle="FBF'nin en çok atlanan parçası. Bağlanmadan grubun hiçbir etkisi yoktur.">
                            <form method="POST" action="{{ route('switches.forwarding.bind', $switch) }}"
                                  data-confirm="Arayüz yollarının kopyalanacağı grup cihaza yazılacak."
                                  data-confirm-title="Cihaza yaz"
                                  :data-confirm-verify="$switch->hostname">
                                @csrf
                                @method('PUT')

                                <div class="form-grid">
                                    <x-form.field name="rib_group" label="RIB grubu" id="connected-group"
                                                  hint="Arayüz yolları (connected) yalnızca inet.0'dadır; seçilen grup onları hedef tabloya kopyalar.">
                                        <x-form.select name="rib_group" id="connected-group"
                                                       :options="$groupOptions" :selected="$boundGroup" />
                                    </x-form.field>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn--primary btn--sm">Cihaza yaz</button>
                                </div>
                            </form>
                        </x-ui.panel>
                    @endcan
                </div>
            </div>
        </div>
    </div>

    @can('createSwitchForwarding', $switch)
        <x-ui.modal id="table-add" title="Yönlendirme tablosu">
            <form method="POST" action="{{ route('switches.forwarding.instances.store', $switch) }}"
                  data-confirm="Yönlendirme tablosu cihaza yazılacak."
                  data-confirm-title="Cihaza yaz">
                @csrf
                <div class="modal__body stack">
                    <x-forwarding.instance-fields prefix="yeni-tablo" :type-options="$typeOptions" />
                </div>
                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>

        <x-ui.modal id="group-add" title="RIB grubu">
            <form method="POST" action="{{ route('switches.forwarding.groups.store', $switch) }}"
                  data-confirm="RIB grubu cihaza yazılacak."
                  data-confirm-title="Cihaza yaz">
                @csrf
                <div class="modal__body stack">
                    <x-forwarding.group-fields prefix="yeni-grup" />
                </div>
                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>
    @endcan

    @can('updateSwitchForwarding', $switch)
        @foreach ($instances as $instance)
            <x-ui.modal :id="'tablo-'.$instance->uuid" :title="$instance->name.' tablosu'">
                <form method="POST" action="{{ route('switches.forwarding.instances.update', [$switch, $instance]) }}"
                      data-confirm="Yönlendirme tablosu cihaza yazılacak."
                      data-confirm-title="Cihaza yaz">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$instance" />

                    <div class="modal__body stack">
                        <x-forwarding.instance-fields :prefix="'tablo-'.$instance->uuid"
                                                      :instance="$instance" :type-options="$typeOptions" />
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endforeach

        @foreach ($groups as $group)
            <x-ui.modal :id="'grup-'.$group->uuid" :title="$group->name.' grubu'">
                <form method="POST" action="{{ route('switches.forwarding.groups.update', [$switch, $group]) }}"
                      data-confirm="RIB grubu cihaza yazılacak."
                      data-confirm-title="Cihaza yaz">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$group" />

                    <div class="modal__body stack">
                        <x-forwarding.group-fields :prefix="'grup-'.$group->uuid" :group="$group" />
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endforeach
    @endcan
</x-layout.app>
