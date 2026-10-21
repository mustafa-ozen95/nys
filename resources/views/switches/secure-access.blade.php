<x-layout.app :title="$switch->hostname.' · DHCP snooping'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">DHCP snooping</span>
                <span class="column__count">{{ $secureVlans->count() + $secureInterfaces->count() }}</span>
                <span class="spacer"></span>
            </div>

            <div class="column__body">
                <x-ui.flash />

                @if ($vlanScopedFields)
                    <x-ui.alert variant="info">
                        Bu cihaz ELS ailesinde; ayarlar cihazda üç ayrı yere yazılır. İki alan
                        VLAN’a bağlıdır, bu yüzden port kuralında VLAN da sorulur:
                        <ul>
                            @foreach ($vlanScopedFields as $neden)
                                <li>{{ $neden }}</li>
                            @endforeach
                        </ul>
                    </x-ui.alert>
                @endif

                {{--
                    VLAN kuralları. Koruma BURADAN açılır: snooping tablosu
                    kurulmadan ARP denetimi ve IP kaynak koruması bir işe
                    yaramaz, açılırlarsa trafiği düşürürler.
                --}}
                <x-ui.panel title="VLAN kuralları"
                            subtitle="DHCP snooping, ARP denetimi ve IP kaynak koruması VLAN başına açılır.">
                    <div class="grid-row__head ayar-row">
                        <span>VLAN</span>
                        <span>Korumalar</span>
                        <span>Sınır</span>
                        <span></span>
                    </div>

                    @forelse ($secureVlans as $rule)
                        <div class="grid-row grid-row--static ayar-row">
                            <span class="mono">{{ $rule->vlan_name }}</span>
                            <span>
                                @forelse ($rule->protections as $koruma)
                                    <x-ui.badge variant="green">{{ $koruma }}</x-ui.badge>
                                @empty
                                    <x-ui.badge variant="gray">Koruma yok</x-ui.badge>
                                @endforelse

                                @if ($rule->preserved_lines)
                                    <x-ui.badge variant="cyan"
                                                title="Panelde gösterilmeyen ama korunan satırlar: {{ implode(', ', $rule->preserved_lines) }}">{{ count($rule->preserved_lines) }} korunan satır</x-ui.badge>
                                @endif
                            </span>
                            <span class="text-muted text-sm">{{ $rule->summary }}</span>
                            <span class="text-right">
                                @can('updateSwitchSecureAccess', $switch)
                                    <button type="button" class="btn btn--sm"
                                            data-modal-open="snoop-vlan-{{ $rule->uuid }}">Düzenle</button>
                                @endcan
                            </span>
                        </div>
                    @empty
                        <x-ui.empty title="VLAN kuralı yok"
                                    text="Cihaz DHCP trafiğini incelemiyor. Sahte bir DHCP sunucusu bütün VLAN’a adres dağıtabilir." />
                    @endforelse

                    @can('createSwitchSecureAccess', $switch)
                        @if ($supported)
                            <button type="button" class="btn btn--sm" data-modal-open="snoop-vlan-ekle">VLAN kuralı ekle</button>
                        @endif
                    @endcan
                </x-ui.panel>

                {{--
                    Arayüz kuralları. "Güvenilir" işaretlenmiş bir ERİŞİM portu
                    korumanın tamamını boşa çıkarır; liste bunu kırmızı rozetle
                    söyler ve rozet sunucuda hesaplanır.
                --}}
                <x-ui.panel title="Arayüz kuralları"
                            subtitle="Hangi portun DHCP sunucusu cevabı geçirebileceği ve porta kaç MAC bağlanabileceği.">
                    <div class="grid-row__head ayar-row">
                        <span>Arayüz</span>
                        <span>DHCP güveni</span>
                        <span>Sınır</span>
                        <span></span>
                    </div>

                    @forelse ($secureInterfaces as $rule)
                        <div class="grid-row grid-row--static ayar-row">
                            <span class="mono">{{ $rule->interface_name }}</span>
                            <span>
                                @if (in_array($rule->uuid, $riskyInterfaces, true))
                                    <x-ui.badge variant="red"
                                                title="Uplink olmayan bir port güvenilir işaretlenmiş; bu porta takılan cihaz ağa DHCP dağıtabilir.">Güvenilir — uplink değil!</x-ui.badge>
                                @else
                                    <x-ui.badge :variant="$rule->dhcp_trusted === false ? 'blue' : 'gray'">{{ $rule->trust_label }}</x-ui.badge>
                                @endif

                                @if ($rule->preserved_lines)
                                    <x-ui.badge variant="cyan"
                                                title="Panelde gösterilmeyen ama korunan satırlar: {{ implode(', ', $rule->preserved_lines) }}">{{ count($rule->preserved_lines) }} korunan satır</x-ui.badge>
                                @endif
                            </span>
                            <span class="text-muted text-sm">{{ $rule->summary }}</span>
                            <span class="text-right">
                                @can('updateSwitchSecureAccess', $switch)
                                    <button type="button" class="btn btn--sm"
                                            data-modal-open="snoop-port-{{ $rule->uuid }}">Düzenle</button>
                                @endcan
                            </span>
                        </div>
                    @empty
                        <x-ui.empty title="Arayüz kuralı yok"
                                    text="Cihazın kendi varsayılanı geçerli: trunk portlar güvenilir, erişim portları güvenilmez." />
                    @endforelse

                    @can('createSwitchSecureAccess', $switch)
                        @if ($supported)
                            <button type="button" class="btn btn--sm" data-modal-open="snoop-port-ekle">Arayüz kuralı ekle</button>
                        @endif
                    @endcan
                </x-ui.panel>
            </div>
        </div>
    </div>

    @can('createSwitchSecureAccess', $switch)
        @if ($supported)
            <x-ui.modal id="snoop-vlan-ekle" title="VLAN güvenlik kuralı">
                <form method="POST" action="{{ route('switches.secure-access.vlan.store', $switch) }}"
                      data-confirm="VLAN güvenlik kuralı cihaza yazılacak. IP kaynak koruması açıksa, adresini DHCP’den ALMAYAN cihazların (yazıcı, sunucu, kamera) trafiği düşürülür. “Portu kapat” eylemi sınırı aşan portu hizmet dışı bırakır."
                      data-confirm-title="Cihaza yaz">
                    @csrf
                    <div class="modal__body stack">
                        <x-form.field kural="switch_secure_access.genel:vlan_name" name="vlan_name" label="VLAN" id="snoop-vlan-ad"
                                      :hint="$allKeyword
                                          ? '“all” bütün VLAN’ları kapsar. Tanımsız bir ad cihazda commit’i düşürür; listede yalnızca bu switchte tanımlı VLAN’lar var.'
                                          : 'Bu Junos ailesi “all” adını ayrılmış sözcük sayar; VLAN tek tek seçilir. Tanımsız bir ad cihazda commit’i düşürür.'">
                            <x-form.select name="vlan_name" id="snoop-vlan-ad" :options="$vlanOptions" />
                        </x-form.field>

                        <x-switching.secure-vlan-fields prefix="snoop-vlan-yeni" :action-options="$actionOptions" />
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>

            <x-ui.modal id="snoop-port-ekle" title="Arayüz güvenlik kuralı">
                <form method="POST" action="{{ route('switches.secure-access.interface.store', $switch) }}"
                      data-confirm="Arayüz güvenlik kuralı cihaza yazılacak. “Güvenilir” YALNIZCA uplink portuna verilir: erişim portuna verilirse o porta takılan cihaz ağın DHCP sunucusu gibi davranabilir ve snooping’in koruması ortadan kalkar. “Portu kapat” eylemi ise portu hizmet dışı bırakır."
                      data-confirm-title="Cihaza yaz">
                    @csrf
                    <div class="modal__body stack">
                        <x-form.field kural="switch_secure_access.genel:interface_name" name="interface_name" label="Arayüz" id="snoop-port-ad"
                                      :hint="$allKeyword
                                          ? '“all” bütün anahtarlama portlarını kapsar — “all” için güvenilir seçmek bütün portları güvenilir yapar.'
                                          : 'Bu Junos ailesi “all” kısayolunu kabul etmez; port tek tek seçilir.'">
                            <x-form.select name="interface_name" id="snoop-port-ad" :options="$interfaceOptions" />
                        </x-form.field>

                        <x-switching.secure-interface-fields prefix="snoop-port-yeni"
                                                             :action-options="$actionOptions"
                                                             :trust-options="$trustOptions"
                                                             :vlan-scoped-fields="$vlanScopedFields"
                                                             :vlan-options="$vlanOptions" />
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endif
    @endcan

    @can('updateSwitchSecureAccess', $switch)
        @foreach ($secureVlans as $rule)
            <x-ui.modal :id="'snoop-vlan-'.$rule->uuid" :title="$rule->vlan_name.' · VLAN güvenliği'">
                <form method="POST" action="{{ route('switches.secure-access.vlan.update', [$switch, $rule]) }}"
                      data-confirm="{{ $rule->vlan_name }} VLAN güvenlik kuralı cihaza yazılacak. IP kaynak koruması açıksa adresini DHCP’den almayan cihazların trafiği düşürülür; “Portu kapat” eylemi sınırı aşan portu hizmet dışı bırakır."
                      data-confirm-title="Cihaza yaz" data-confirm-verify="{{ $switch->hostname }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$rule" />
                    <div class="modal__body stack">
                        <x-switching.secure-vlan-fields :rule="$rule" :prefix="'sv'.$rule->uuid"
                                                        :action-options="$actionOptions" />
                    </div>
                    <x-ui.modal-actions :submit="$supported ? 'Cihaza yaz' : false">
                        @can('deleteSwitchSecureAccess', $switch)
                            <button type="submit" form="snoop-vlan-sil-{{ $rule->uuid }}"
                                    class="btn btn--danger btn--sm">Sil</button>
                            <span class="spacer"></span>
                        @endcan
                    </x-ui.modal-actions>
                </form>

                @can('deleteSwitchSecureAccess', $switch)
                    <form method="POST" id="snoop-vlan-sil-{{ $rule->uuid }}" hidden
                          action="{{ route('switches.secure-access.vlan.destroy', [$switch, $rule]) }}"
                          data-confirm="{{ $rule->vlan_name }} VLAN’ının güvenlik kuralı CİHAZDAN silinecek; DHCP snooping ve ona dayanan korumalar o VLAN’da kalkar."
                          data-confirm-title="Kuralı sil" data-confirm-variant="danger"
                          data-confirm-verify="{{ $switch->hostname }}">
                        @csrf
                        @method('DELETE')
                    </form>
                @endcan
            </x-ui.modal>
        @endforeach

        @foreach ($secureInterfaces as $rule)
            <x-ui.modal :id="'snoop-port-'.$rule->uuid" :title="$rule->interface_name.' · port güvenliği'">
                <form method="POST" action="{{ route('switches.secure-access.interface.update', [$switch, $rule]) }}"
                      data-confirm="{{ $rule->interface_name }} port güvenlik kuralı cihaza yazılacak. “Güvenilir” YALNIZCA uplink portuna verilir: erişim portuna verilirse o porta takılan cihaz ağın DHCP sunucusu gibi davranabilir. “Portu kapat” eylemi portu hizmet dışı bırakır."
                      data-confirm-title="Cihaza yaz" data-confirm-verify="{{ $switch->hostname }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$rule" />
                    <div class="modal__body stack">
                        <x-switching.secure-interface-fields :rule="$rule" :prefix="'si'.$rule->uuid"
                                                             :action-options="$actionOptions"
                                                             :trust-options="$trustOptions"
                                                             :vlan-scoped-fields="$vlanScopedFields"
                                                             :vlan-options="$vlanOptions" />
                    </div>
                    <x-ui.modal-actions :submit="$supported ? 'Cihaza yaz' : false">
                        @can('deleteSwitchSecureAccess', $switch)
                            <button type="submit" form="snoop-port-sil-{{ $rule->uuid }}"
                                    class="btn btn--danger btn--sm">Sil</button>
                            <span class="spacer"></span>
                        @endcan
                    </x-ui.modal-actions>
                </form>

                @can('deleteSwitchSecureAccess', $switch)
                    <form method="POST" id="snoop-port-sil-{{ $rule->uuid }}" hidden
                          action="{{ route('switches.secure-access.interface.destroy', [$switch, $rule]) }}"
                          data-confirm="{{ $rule->interface_name }} arayüzünün güvenlik kuralı CİHAZDAN silinecek; port cihazın varsayılan davranışına döner."
                          data-confirm-title="Kuralı sil" data-confirm-variant="danger"
                          data-confirm-verify="{{ $switch->hostname }}">
                        @csrf
                        @method('DELETE')
                    </form>
                @endcan
            </x-ui.modal>
        @endforeach
    @endcan
</x-layout.app>
