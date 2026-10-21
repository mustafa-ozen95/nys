<x-layout.app :title="$switch->hostname.' · BGP'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">BGP</span>
                <span class="column__count">{{ $summary['groups'] }}</span>
                <span class="spacer"></span>

                @can('updateSwitchBgp', $switch)
                    @if ($hasAutonomousSystem)
                        <button type="button" class="btn btn--primary btn--sm" data-modal-open="grup-ekle">Grup ekle</button>
                    @endif

                    <button type="button" class="btn btn--sm" data-modal-open="as-numarasi">AS numarası</button>
                @endcan
            </div>

            <div class="column__body column__body--padded">
                <div class="stack stack--loose">
                    {{--
                        Cihaz, AS numarası tanımlı değilken BGP'yi commit
                        etmiyor: "must define local autonomous system when
                        enabling BGP". Ekran bu yüzden adım adımdır.
                    --}}
                    @unless ($hasAutonomousSystem)
                        <x-ui.alert variant="warning">
                            Bu switchin AS numarası tanımlı değil. Cihaz, AS numarası olmadan
                            hiçbir BGP yapılandırmasını kabul etmiyor; önce onu tanımlayın.
                        </x-ui.alert>
                    @endunless

                    <x-ui.panel title="Özet">
                        <x-ui.facts :rows="[
                            'AS numarası' => $switch->autonomous_system ?? 'Tanımlı değil',
                            'Grup' => $summary['groups'],
                            'Komşu' => $summary['neighbors'],
                            'Kurulu oturum' => $summary['established'].' / '.$summary['neighbors'],
                        ]" />
                    </x-ui.panel>

                    @if ($unmanageablePolicies !== [])
                        <x-ui.alert variant="info">
                            Şu politikalar panelin temsil ettiği yapının dışında ve buradan
                            düzenlenemez (gruplarda seçilebilirler):
                            <span class="mono">{{ implode(', ', $unmanageablePolicies) }}</span>
                        </x-ui.alert>
                    @endif

                    @forelse ($groups as $group)
                        <x-ui.panel :title="$group->name" :subtitle="$group->type->label()">
                            <x-slot:actions>
                                <x-ui.inactive-badge :inactive="$group->is_inactive" />

                                @can('createSwitchBgp', $switch)
                                    <button type="button" class="btn btn--sm"
                                            data-modal-open="komsu-ekle-{{ $group->uuid }}">Komşu ekle</button>

                                    <button type="button" class="btn btn--sm"
                                            data-modal-open="grup-duzenle-{{ $group->uuid }}">Düzenle</button>

                                    <x-ui.delete-form :action="route('switches.bgp.groups.destroy', [$switch, $group])"
                                                      confirm="{{ $group->name }} grubu ve altındaki {{ $group->neighbors->count() }} komşu cihazdan silinecek."
                                                      title="Grubu sil"
                                                      verify="{{ $group->name }}" />
                                @endcan
                            </x-slot:actions>

                            <x-ui.facts :rows="[
                                'Karşı taraf AS' => $group->peer_asn,
                                'Yerel adres' => $group->local_address,
                                'Yerel AS' => $group->local_asn,
                                'Multihop' => $group->multihop ? ($group->multihop_ttl ? 'TTL '.$group->multihop_ttl : 'Açık') : null,
                                'Pasif' => $group->passive ? 'Bağlantıyı karşı taraf başlatır' : null,
                                'Günlük' => $group->log_updown ? 'Durum değişiklikleri yazılır' : null,
                                'İçeri alma' => $group->import_policy_list ?: null,
                                'Dışarı verme' => $group->export_policy_list ?: null,
                                'Açıklama' => $group->description,
                            ]" />

                            @foreach ($group->families as $family)
                                {{-- İki öğelik basit satır; ızgaraya gerek yok. --}}
                                <div class="row row--between">
                                    <x-ui.badge :variant="$family->family->badge()">{{ $family->family->label() }}</x-ui.badge>
                                    <span class="text-sm text-muted">{{ $family->limit_summary }}</span>
                                </div>
                            @endforeach

                            @forelse ($group->neighbors as $neighbor)
                                <div class="grid-row peer-row">
                                    <span class="mono">{{ $neighbor->address }}</span>

                                    <x-ui.badge :variant="$neighbor->state->badge()">{{ $neighbor->state->label() }}</x-ui.badge>
                                    <x-ui.inactive-badge :inactive="$neighbor->is_inactive" />

                                    <span class="text-sm text-muted">
                                        AS {{ $neighbor->effective_peer_asn ?? '—' }}
                                        @if ($neighbor->received_prefixes !== null)
                                            · {{ $neighbor->received_prefixes }} prefix
                                        @endif
                                        @if ($neighbor->state_changed_for)
                                            · {{ $neighbor->state_changed_for }}
                                        @endif
                                    </span>

                                    <span class="text-sm text-muted">{{ $neighbor->description }}</span>

                                    <span class="row-actions">
                                        <button type="button" class="btn btn--ghost btn--sm"
                                                data-modal-open="alinan-{{ $neighbor->uuid }}"
                                                data-alinan="{{ route('ajax.bgp.received', [$switch, $neighbor]) }}"
                                                data-alinan-hedef="#alinan-govde-{{ $neighbor->uuid }}">Alınan prefixler</button>

                                        <button type="button" class="btn btn--ghost btn--sm"
                                                data-modal-open="peer-{{ $neighbor->uuid }}">Müşteri configi</button>
                                    </span>

                                    @can('updateSwitchBgp', $switch)
                                        <span class="row-actions">
                                            <button type="button" class="btn btn--ghost btn--sm"
                                                    data-modal-open="komsu-{{ $neighbor->uuid }}">Düzenle</button>

                                            <x-ui.delete-form :action="route('switches.bgp.neighbors.destroy', [$switch, $group, $neighbor])"
                                                              confirm="{{ $neighbor->address }} komşusu cihazdan silinecek."
                                                              title="Komşuyu sil"
                                                              verify="{{ $neighbor->address }}" />
                                        </span>
                                    @endcan
                                </div>
                            @empty
                                <p class="text-sm text-muted">Bu grupta komşu yok.</p>
                            @endforelse
                        </x-ui.panel>
                    @empty
                        <x-ui.empty title="BGP grubu yok"
                                    text="Cihazda tanımlı BGP grubu bulunamadı. Gruplar cihazdan eşitlenir." />
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{--
        Karşı tarafın configi salt okunurdur ve cihaza hiçbir şey yazmaz;
        bu yüzden "configure" değil "view" yetkisi yeter.
    --}}
    {{--
        Alınan prefixler cihazdan OKUNUR ve saklanmaz: tablo binlerce satır
        olabilir ve sürekli değişir. Pencere açılınca yüklenir.
    --}}
    @foreach ($groups as $group)
        @foreach ($group->neighbors as $neighbor)
            <x-ui.modal :id="'alinan-'.$neighbor->uuid" :title="$neighbor->address.' · alınan prefixler'" wide>
                <div class="modal__body stack stack--loose" id="alinan-govde-{{ $neighbor->uuid }}">
                    <p class="text-sm text-muted">Cihazdan okunuyor…</p>
                </div>

                <x-ui.modal-actions :submit="false" cancel="Kapat" />
            </x-ui.modal>
        @endforeach
    @endforeach

    @foreach ($groups as $group)
        @foreach ($group->neighbors as $neighbor)
            <x-ui.modal :id="'peer-'.$neighbor->uuid" :title="$neighbor->address.' · oturum configi'" wide>
                <x-bgp.peer-configuration :neighbor="$neighbor"
                                          :configuration="$peerConfigurations[$neighbor->uuid]"
                                          :ours="$ourConfigurations[$neighbor->uuid]" />
            </x-ui.modal>
        @endforeach
    @endforeach

    @can('createSwitchBgp', $switch)
        <x-ui.modal id="as-numarasi" title="AS numarası">
            <form method="POST" action="{{ route('switches.bgp.autonomous-system', $switch) }}"
                  data-confirm="Bu işlem cihaza commit atar."
                  data-confirm-title="Cihaza yaz"
                  data-confirm-verify="{{ $switch->hostname }}">
                @csrf
                @method('PUT')

                <div class="modal__body">
                    <x-form.field name="autonomous_system" label="AS numarası" id="as-no"
                                  hint="routing-options autonomous-system. Cihaz bu tanımlı değilken BGP’yi kabul etmiyor.">
                        <x-form.input name="autonomous_system" id="as-no"
                                      :value="$switch->autonomous_system" mono required />
                    </x-form.field>
                </div>

                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>

        @if ($hasAutonomousSystem)
            <x-ui.modal id="grup-ekle" title="Yeni BGP grubu" wide>
                <form method="POST" action="{{ route('switches.bgp.groups.store', $switch) }}"
                      data-bgp-formu
                      data-confirm="Bu işlem cihaza commit atar."
                      data-confirm-title="Cihaza yaz">
                    @csrf

                    <x-bgp.group-fields :policy-options="$policyOptions" :type-options="$typeOptions"
                                        :nlri-options="$nlriOptions"
                                        :graceful-restart-options="$gracefulRestartOptions"
                                        :peering-address-options="$peeringAddressOptions" prefix="yeni" />

                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endif

        @foreach ($groups as $group)
            <x-ui.modal :id="'grup-duzenle-'.$group->uuid" :title="$group->name.' düzenle'" wide>
                <form method="POST" action="{{ route('switches.bgp.groups.update', [$switch, $group]) }}"
                      data-bgp-formu
                      data-confirm="Bu işlem cihaza commit atar."
                      data-confirm-title="Cihaza yaz"
                      data-confirm-verify="{{ $group->name }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$group" />

                    <x-bgp.group-fields :group="$group" :policy-options="$policyOptions"
                                        :type-options="$typeOptions" :nlri-options="$nlriOptions"
                                        :graceful-restart-options="$gracefulRestartOptions"
                                        :peering-address-options="$peeringAddressOptions"
                                        :prefix="'g'.$group->uuid" />

                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>

            <x-ui.modal :id="'komsu-ekle-'.$group->uuid" :title="$group->name.' · komşu ekle'">
                <form method="POST" action="{{ route('switches.bgp.neighbors.store', [$switch, $group]) }}"
                      data-bgp-formu
                      data-confirm="Bu işlem cihaza commit atar."
                      data-confirm-title="Cihaza yaz">
                    @csrf

                    <x-bgp.neighbor-fields :prefix="'nk'.$group->uuid"
                                           :peering-address-options="$peeringAddressOptions"
                                           :policy-options="$policyOptions" />

                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>

            @foreach ($group->neighbors as $neighbor)
                <x-ui.modal :id="'komsu-'.$neighbor->uuid" :title="$neighbor->address.' düzenle'">
                    <form method="POST"
                          action="{{ route('switches.bgp.neighbors.update', [$switch, $group, $neighbor]) }}"
                          data-bgp-formu
                          data-confirm="Bu işlem cihaza commit atar."
                          data-confirm-title="Cihaza yaz"
                          data-confirm-verify="{{ $neighbor->address }}">
                        @csrf
                        @method('PUT')
                        <x-form.version :record="$neighbor" />

                        <x-bgp.neighbor-fields :neighbor="$neighbor" :prefix="'n'.$neighbor->uuid"
                                               :peering-address-options="$peeringAddressOptions"
                                               :policy-options="$policyOptions" />

                        <x-ui.modal-actions submit="Cihaza yaz" />
                    </form>
                </x-ui.modal>
            @endforeach
        @endforeach
    @endcan
</x-layout.app>
