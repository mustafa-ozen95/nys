<x-layout.app :title="$switch->hostname.' · Firewall'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Firewall</span>
                <span class="column__count">{{ $filters->count() }}</span>
                <span class="spacer"></span>

                @can('createSwitchFirewall', $switch)
                    <button type="button" class="btn btn--sm" data-modal-open="policer-ekle">Policer ekle</button>
                    <button type="button" class="btn btn--primary btn--sm" data-modal-open="filtre-ekle">Filtre ekle</button>
                @endcan
            </div>

            <div class="column__body column__body--padded">
                <div class="stack stack--loose">
                    @cannot('updateSwitchFirewall', $switch)
                        <x-ui.alert variant="info">
                            Firewall kurallarını değiştirme yetkiniz yok; ekran salt okunurdur.
                            Yanlış bir filtre bütün trafiği kesebilir ve cihaza erişimi koparabilir.
                        </x-ui.alert>
                    @endcannot

                    {{--
                        Policer'lar filtrenin DIŞINDA, "firewall" düğümünün
                        doğrudan altında durur; terimler onları adla çağırır.
                        Panel onları da okur; okumasaydı policer kullanan her
                        filtre salt okunur kalırdı.
                    --}}
                    <x-ui.panel title="Policer'lar"
                                subtitle="Terimlerin çağırdığı hız sınırları. Cihazda filtrenin dışında dururlar.">
                        @forelse ($policers as $policer)
                            <div class="grid-row ayar-row">
                                <span class="mono">{{ $policer->name }}</span>
                                <x-ui.badge :variant="$policer->badge">{{ $policer->state_label }}</x-ui.badge>
                                <span class="text-sm text-muted">{{ $policer->summary }}</span>

                                @can('updateSwitchFirewall', $switch)
                                    <span class="row-actions">
                                        <button type="button" class="btn btn--ghost btn--sm"
                                                data-modal-open="policer-{{ $policer->uuid }}">Düzenle</button>

                                        <x-ui.delete-form :action="route('switches.firewall.policers.destroy', [$switch, $policer])"
                                                          confirm="{{ $policer->name }} policer'ı cihazdan silinecek. Onu çağıran bir terim varsa cihaz commit'i reddeder."
                                                          title="Policer'ı sil"
                                                          verify="{{ $policer->name }}" />
                                    </span>
                                @endcan
                            </div>
                        @empty
                            <p class="text-sm text-muted">Bu switchte tanımlı policer yok.</p>
                        @endforelse
                    </x-ui.panel>

                    @forelse ($filters as $filter)
                        <x-ui.panel :title="$filter->name"
                                    :subtitle="$filter->family->label().' · '.$filter->binding_summary">
                            <x-slot:actions>
                                <x-ui.inactive-badge :inactive="$filter->is_inactive" />

                                @can('createSwitchFirewall', $switch)
                                    <button type="button" class="btn btn--sm"
                                            data-modal-open="fw-terim-{{ $filter->uuid }}">Terim ekle</button>
                                @endcan

                                @can('updateSwitchFirewall', $switch)
                                    <button type="button" class="btn btn--ghost btn--sm"
                                            data-modal-open="fw-ayar-{{ $filter->uuid }}">Ayarlar</button>
                                @endcan

                                @can('deleteSwitchFirewall', $switch)
                                    <x-ui.delete-form :action="route('switches.firewall.destroy', [$switch, $filter])"
                                                      confirm="{{ $filter->name }} filtresi cihazdan silinecek.{{ $filter->is_bound ? ' Bu filtre '.$filter->binding_summary.' olarak BAĞLI; silinince o arayüzdeki süzme tamamen kalkar.' : '' }}"
                                                      title="Filtreyi sil"
                                                      verify="{{ $filter->name }}" />
                                @endcan
                            </x-slot:actions>

                            {{--
                                Panelin tanımadığı satırlar filtreyi salt okunur
                                yapmaz: ham hâlleriyle saklanıp geri yazılır;
                                kullanıcıya SAYISI söylenir, düzenleme yolu açık
                                kalır.
                            --}}
                            @if ($filter->preserved_lines)
                                <x-ui.alert variant="info">
                                    Bu filtrede panelin göstermediği {{ count($filter->preserved_lines) }} satır var
                                    ({{ implode(', ', $filter->preserved_lines) }}).
                                    Düzenlediğinizde cihazda olduğu gibi korunurlar.
                                </x-ui.alert>
                            @endif

                            @if ($filter->interface_specific)
                                <p class="text-sm text-muted">
                                    Sayaçlar arayüz başına ayrı tutuluyor (interface-specific).
                                </p>
                            @endif

                            @unless ($filter->is_bound)
                                <p class="text-sm text-muted">
                                    Bu filtre hiçbir arayüze bağlı değil; şu an hiçbir trafiği etkilemiyor.
                                </p>
                            @endunless

                            {{--
                                Sıra trafiğin kendisidir: ilk eşleşen terim
                                kazanır. Satırlar sürüklenerek sıralanır ve
                                bırakma anında cihaza yazılır.
                            --}}
                            <div @can('updateSwitchFirewall', $switch) data-sira="{{ route('switches.firewall.terms.order', [$switch, $filter]) }}" @endcan>
                            @foreach ($filter->terms as $term)
                                <div class="grid-row term-row"
                                     @can('updateSwitchFirewall', $switch) draggable="true" data-sira-kimlik="{{ $term->uuid }}" @endcan>
                                    <span class="term-row__grip" aria-hidden="true">⠿</span>
                                    <span class="mono term-row__ad">{{ $loop->iteration }}. {{ $term->name }}</span>
                                    <span class="text-sm text-muted term-row__ozet">{{ $term->summary }}</span>
                                    <x-ui.inactive-badge :inactive="$term->is_inactive" />

                                    @can('updateSwitchFirewall', $switch)
                                        <span class="row-actions">
                                            <button type="button" class="btn btn--ghost btn--sm"
                                                    data-modal-open="fwd-{{ $term->uuid }}">Düzenle</button>

                                            @if ($filter->terms->count() > 1)
                                                <x-ui.delete-form :action="route('switches.firewall.terms.destroy', [$switch, $filter, $term])"
                                                                  confirm="{{ $term->name }} terimi çıkarılacak. Terim sırası trafiği doğrudan etkiler."
                                                                  title="Terimi çıkar"
                                                                  verify="{{ $term->name }}"
                                                                  label="Çıkar" />
                                            @endif
                                        </span>
                                    @endcan
                                </div>
                            @endforeach
                            </div>
                        </x-ui.panel>
                    @empty
                        <x-ui.empty title="Firewall filtresi yok"
                                    text="Filtreler cihazdan eşitlenir." />
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @can('createSwitchFirewall', $switch)
        <x-ui.modal id="filtre-ekle" title="Yeni firewall filtresi" wide>
            <form method="POST" action="{{ route('switches.firewall.store', $switch) }}"
                  data-confirm="Bu işlem cihaza commit atar. Yeni filtre bir arayüze bağlanana kadar trafiği etkilemez."
                  data-confirm-title="Cihaza yaz">
                @csrf
                <div class="modal__body stack">
                    <x-form.field name="filter_name" label="Filtre adı" id="fw-ad">
                        <x-form.input name="filter_name" id="fw-ad" mono required placeholder="koruma" />
                    </x-form.field>

                    {{--
                        Aile seçimi koşul listesini değiştirir: next-header
                        yalnızca IPv6'da, ether-type ve MAC yalnızca
                        ethernet-switching'de bulunur. Aynı kısıt sunucuda da
                        vardır; alanı gizlemek koruma değildir.
                    --}}
                    <x-form.field kural="switch_firewall.filtre:family" name="family" label="Adres ailesi" id="fw-aile"
                                  hint="Ailenin koşulları farklıdır; seçime göre aşağıdaki alanlar değişir.">
                        <x-form.select name="family" id="fw-aile" :options="$familyOptions" selected="inet"
                                       data-fw-family />
                    </x-form.field>

                    <x-form.field kural="switch_firewall.filtre:interface_specific" name="interface_specific" label="Sayaç kapsamı" id="fw-is">
                        <label class="checkbox">
                            <input type="hidden" name="interface_specific" value="0">
                            <input type="checkbox" name="interface_specific" id="fw-is" value="1">
                            <span>Arayüz başına ayrı sayaç (interface-specific)</span>
                        </label>
                    </x-form.field>

                    <x-firewall.term-fields prefix="fw" :prefix-list-options="$prefixListOptions"
                                            :protocol-options="$protocolOptions"
                                            :next-header-options="$nextHeaderOptions"
                                            :icmp-type-options="$icmpTypeOptions"
                                            :ip-option-options="$ipOptionOptions"
                                            :fragment-flag-options="$fragmentFlagOptions"
                                            :precedence-options="$precedenceOptions"
                                            :ether-type-options="$etherTypeOptions"
                                            :dscp-match-options="$dscpMatchOptions"
                                            :action-options="$actionOptions"
                                            :policer-options="$policerOptions"
                                            :loss-priority-options="$lossPriorityOptions"
                                            :forwarding-class-options="$forwardingClassOptions"
                                            :dscp-options="$dscpOptions" />
                </div>
                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>

        <x-ui.modal id="policer-ekle" title="Yeni policer">
            <form method="POST" action="{{ route('switches.firewall.policers.store', $switch) }}"
                  data-confirm="Bu işlem cihaza commit atar. Policer bir terim tarafından çağrılana kadar trafiği etkilemez."
                  data-confirm-title="Cihaza yaz">
                @csrf
                <div class="modal__body stack">
                    <x-firewall.policer-fields prefix="pol-yeni"
                                               :loss-priority-options="$lossPriorityOptions"
                                               :forwarding-class-options="$forwardingClassOptions" />
                </div>
                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>

        {{-- Terim düzenleme: içerik ve SIRA burada değişir. --}}
        @foreach ($filters as $filter)
            @foreach ($filter->terms as $term)
                <x-ui.modal :id="'fwd-'.$term->uuid" :title="$filter->name.' · '.$term->name" wide>
                    <form method="POST"
                          action="{{ route('switches.firewall.terms.update', [$switch, $filter, $term]) }}"
                          data-confirm="Bu işlem cihaza commit atar. Sıra değişirse hangi trafiğin süzüleceği de değişir."
                          data-confirm-title="Cihaza yaz"
                          data-confirm-verify="{{ $term->name }}">
                        @csrf
                        @method('PUT')
                        <x-form.version :record="$term" />
                        <div class="modal__body stack">
                            <x-firewall.term-fields :term="$term" :prefix="'fwd'.$term->uuid"
                                                    :family="$filter->family"
                                                    :position-options="$positionOptions[$filter->uuid]"
                                                    :prefix-list-options="$prefixListOptions"
                                                    :protocol-options="$protocolOptions"
                                                    :next-header-options="$nextHeaderOptions"
                                                    :icmp-type-options="$icmpTypeOptions"
                                                    :ip-option-options="$ipOptionOptions"
                                                    :fragment-flag-options="$fragmentFlagOptions"
                                                    :precedence-options="$precedenceOptions"
                                                    :ether-type-options="$etherTypeOptions"
                                                    :dscp-match-options="$dscpMatchOptions"
                                                    :action-options="$actionOptions"
                                                    :policer-options="$policerOptions"
                                                    :loss-priority-options="$lossPriorityOptions"
                                                    :forwarding-class-options="$forwardingClassOptions"
                                                    :dscp-options="$dscpOptions" />
                        </div>
                        <x-ui.modal-actions submit="Cihaza yaz" />
                    </form>
                </x-ui.modal>
            @endforeach
        @endforeach

        @foreach ($filters as $filter)
            <x-ui.modal :id="'fw-terim-'.$filter->uuid" :title="$filter->name.' · terim ekle'" wide>
                <form method="POST" action="{{ route('switches.firewall.terms.store', [$switch, $filter]) }}"
                      data-confirm="Terim listenin SONUNA eklenir. Junos'ta ilk eşleşen terim kazanır; sona eklenen terim önündeki bir 'discard' yüzünden hiç çalışmayabilir."
                      data-confirm-title="Cihaza yaz">
                    @csrf
                    <div class="modal__body stack">
                        <x-firewall.term-fields :prefix="'fwt'.$filter->uuid"
                                                :family="$filter->family"
                                                :position-options="$positionOptions[$filter->uuid]"
                                                :prefix-list-options="$prefixListOptions"
                                                :protocol-options="$protocolOptions"
                                                :next-header-options="$nextHeaderOptions"
                                                :icmp-type-options="$icmpTypeOptions"
                                                :ip-option-options="$ipOptionOptions"
                                                :fragment-flag-options="$fragmentFlagOptions"
                                                :precedence-options="$precedenceOptions"
                                                :ether-type-options="$etherTypeOptions"
                                                :dscp-match-options="$dscpMatchOptions"
                                                :action-options="$actionOptions"
                                                :policer-options="$policerOptions"
                                                :loss-priority-options="$lossPriorityOptions"
                                                :forwarding-class-options="$forwardingClassOptions"
                                                :dscp-options="$dscpOptions" />
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endforeach
    @endcan

    @can('updateSwitchFirewall', $switch)
        {{--
            Filtre seviyesi ayarlar. Ad ve aile burada DEĞİŞTİRİLEMEZ: cihazda
            filtrenin kimliği adıdır ve arayüz bağlamaları adla tutulur.
        --}}
        @foreach ($filters as $filter)
            <x-ui.modal :id="'fw-ayar-'.$filter->uuid" :title="$filter->name.' · ayarlar'">
                <form method="POST" action="{{ route('switches.firewall.update', [$switch, $filter]) }}"
                      data-confirm="Bu işlem cihaza commit atar; filtre bütün terimleriyle yeniden yazılır."
                      data-confirm-title="Cihaza yaz"
                      data-confirm-verify="{{ $filter->name }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$filter" />
                    <div class="modal__body stack">
                        <x-form.field kural="switch_firewall.filtre:interface_specific" name="interface_specific" label="Sayaç kapsamı"
                                      :id="'fwa'.$filter->uuid"
                                      hint="Açıkken sayaçlar filtrenin bağlı olduğu her arayüz için ayrı tutulur.">
                            <label class="checkbox">
                                <input type="hidden" name="interface_specific" value="0">
                                <input type="checkbox" name="interface_specific"
                                       id="fwa{{ $filter->uuid }}" value="1"
                                       @checked($filter->interface_specific)>
                                <span>Arayüz başına ayrı sayaç (interface-specific)</span>
                            </label>
                        </x-form.field>

                        @if ($filter->preserved_lines)
                            <x-ui.alert variant="info">
                                Panelin göstermediği {{ count($filter->preserved_lines) }} satır
                                ({{ implode(', ', $filter->preserved_lines) }}) olduğu gibi korunur.
                            </x-ui.alert>
                        @endif
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endforeach

        @foreach ($policers as $policer)
            <x-ui.modal :id="'policer-'.$policer->uuid" :title="$policer->name.' · policer'">
                <form method="POST" action="{{ route('switches.firewall.policers.update', [$switch, $policer]) }}"
                      data-confirm="Bu işlem cihaza commit atar. Policer'ı çağıran terimlerin hız sınırı hemen değişir."
                      data-confirm-title="Cihaza yaz"
                      data-confirm-verify="{{ $policer->name }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$policer" />
                    <div class="modal__body stack">
                        <x-firewall.policer-fields :policer="$policer" :prefix="'pol'.$policer->uuid"
                                                   :loss-priority-options="$lossPriorityOptions"
                                                   :forwarding-class-options="$forwardingClassOptions" />
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endforeach
    @endcan
</x-layout.app>
