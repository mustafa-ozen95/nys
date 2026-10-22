<x-layout.app :title="$switch->hostname.' · Politikalar'">
    <div class="columns">
        <x-nav.columns :switches="$switches" :switch="$switch" :objects="$objects" />

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Politikalar</span>
                <span class="column__count">{{ $policies->count() }}</span>
                <span class="spacer"></span>

                @can('createSwitchPolicies', $switch)
                    <button type="button" class="btn btn--sm" data-modal-open="as-path-add">As-path ekle</button>
                    <button type="button" class="btn btn--sm" data-modal-open="community-add">Community ekle</button>
                    <button type="button" class="btn btn--primary btn--sm" data-modal-open="policy-add">Politika ekle</button>
                @endcan
            </div>

            <div class="column__body column__body--padded">
                <div class="stack stack--loose">
                    <x-ui.panel title="Community tanımları"
                                subtitle="Politika terimleri bunlara atıfta bulunur; tanımsız ada atıf commit’i düşürür.">
                        @forelse ($communities as $community)
                            <div class="grid-row community-row">
                                <span class="mono">{{ $community->name }}</span>
                                <span class="mono text-muted">{{ $community->member_list }}</span>

                                @can('updateSwitchPolicies', $switch)
                                    <span class="row-actions">
                                        <button type="button" class="btn btn--ghost btn--sm"
                                                data-modal-open="community-{{ $community->uuid }}">Düzenle</button>

                                        <x-ui.delete-form :action="route('switches.communities.destroy', [$switch, $community])"
                                                          confirm="{{ $community->name }} community’si cihazdan silinecek."
                                                          title="Community sil"
                                                          verify="{{ $community->name }}" />
                                    </span>
                                @endcan
                            </div>
                        @empty
                            <p class="text-sm text-muted">Tanımlı community yok.</p>
                        @endforelse
                    </x-ui.panel>

                    {{--
                        as-path tanımları. Terim "from as-path <ad>" ile
                        bunlara atıfta bulunur; community’de olduğu gibi
                        tanımsız ada atıf commit’i düşürür.
                    --}}
                    <x-ui.panel title="AS yolu tanımları"
                                subtitle="Düzenli ifadeyle AS yolu eşleştirir. Örnek: “.* 65000” = yolun sonu 65000 olan duyurular.">
                        @forelse ($asPaths as $asPath)
                            <div class="grid-row community-row">
                                <span class="mono">{{ $asPath->name }}</span>
                                <span class="mono text-muted">{{ $asPath->path }}</span>

                                @can('updateSwitchPolicies', $switch)
                                    <span class="row-actions">
                                        <button type="button" class="btn btn--ghost btn--sm"
                                                data-modal-open="as-path-{{ $asPath->uuid }}">Düzenle</button>

                                        <x-ui.delete-form :action="route('switches.as-paths.destroy', [$switch, $asPath])"
                                                          confirm="{{ $asPath->name }} as-path tanımı cihazdan silinecek."
                                                          title="As-path sil"
                                                          verify="{{ $asPath->name }}" />
                                    </span>
                                @endcan
                            </div>
                        @empty
                            <p class="text-sm text-muted">Tanımlı as-path yok.</p>
                        @endforelse
                    </x-ui.panel>

                    @forelse ($policies as $policy)
                        <x-ui.panel :title="$policy->name"
                                    :subtitle="$policy->terms->count().' terim · ilk eşleşen kazanır'">
                            <x-slot:actions>
                                <x-ui.inactive-badge :inactive="$policy->is_inactive" />

                                @can('createSwitchPolicies', $switch)
                                    <button type="button" class="btn btn--sm"
                                            data-modal-open="terim-ekle-{{ $policy->uuid }}">Terim ekle</button>

                                    <x-ui.delete-form :action="route('switches.policies.destroy', [$switch, $policy])"
                                                      confirm="{{ $policy->name }} politikası cihazdan silinecek. Ona dayanan BGP grupları politikasız kalır."
                                                      title="Politikayı sil"
                                                      verify="{{ $policy->name }}" />
                                @endcan
                            </x-slot:actions>

                            {{--
                                Panelin göstermediği ama KORUNAN satırlar.
                                Bu satırlar politikayı salt okunur yapmaz: ham
                                hâliyle taşınır ve politika düzenlenebilir kalır.
                            --}}
                            @if ($policy->preserved_lines)
                                <x-ui.alert variant="info">
                                    Bu politikada panelin göstermediği {{ count($policy->preserved_lines) }} satır var
                                    ({{ implode(', ', $policy->preserved_lines) }}). Her kaydetmede cihazda olduğu gibi korunurlar.
                                </x-ui.alert>
                            @endif

                            {{--
                                Politikanın VARSAYILAN DAVRANIŞI: terimlerden
                                sonra gelen "then reject;". Bütün BGP
                                süzgeçlerinin standart kapanışıdır.
                            --}}
                            <div class="grid-row community-row">
                                <span>Varsayılan davranış</span>
                                <span class="text-sm text-muted">{{ $policy->default_summary }}</span>

                                @can('updateSwitchPolicies', $switch)
                                    <span class="row-actions">
                                        <button type="button" class="btn btn--ghost btn--sm"
                                                data-modal-open="pv-{{ $policy->uuid }}">Düzenle</button>
                                    </span>
                                @endcan
                            </div>

                            {{--
                                Sıra politikanın kendisidir: ilk eşleşen terim
                                kazanır. Satırlar sürüklenerek sıralanır.
                            --}}
                            <div @can('updateSwitchPolicies', $switch) data-order="{{ route('switches.policies.terms.order', [$switch, $policy]) }}" @endcan>
                            @foreach ($policy->terms as $term)
                                <div class="grid-row term-row"
                                     @can('updateSwitchPolicies', $switch) draggable="true" data-order-id="{{ $term->uuid }}" @endcan>
                                    <span class="term-row__grip" aria-hidden="true">⠿</span>
                                    <span class="mono term-row__name">{{ $loop->iteration }}. {{ $term->name }}</span>
                                    <span class="text-sm text-muted term-row__summary">{{ $term->summary }}</span>
                                    <x-ui.inactive-badge :inactive="$term->is_inactive" />

                                    @can('updateSwitchPolicies', $switch)
                                        <span class="row-actions">
                                            <button type="button" class="btn btn--ghost btn--sm"
                                                    data-modal-open="pd-{{ $term->uuid }}">Düzenle</button>

                                            @if ($policy->terms->count() > 1)
                                                <x-ui.delete-form :action="route('switches.policies.terms.destroy', [$switch, $policy, $term])"
                                                                  confirm="{{ $term->name }} terimi çıkarılacak."
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
                        <x-ui.empty title="Politika yok"
                                    text="Politikalar cihazdan eşitlenir. BGP grubunun içeri alma ve dışarı verme alanları buradaki adları kullanır." />
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @can('createSwitchPolicies', $switch)
        <x-ui.modal id="community-add" title="Yeni community">
            <form method="POST" action="{{ route('switches.communities.store', $switch) }}"
                  data-confirm="Bu işlem cihaza commit atar." data-confirm-title="Cihaza yaz">
                @csrf
                <div class="modal__body stack">
                    <x-form.field name="name" label="Community adı" id="ce-name">
                        <x-form.input name="name" id="ce-name" mono required placeholder="topluluk-a" />
                    </x-form.field>

                    <x-form.field name="members" label="Üyeler" id="ce-member"
                                  hint="Her satıra bir üye: 65000:666, *:666 ya da no-export.">
                        <x-form.textarea name="members" id="ce-member" rows="3" required />
                    </x-form.field>

                    <x-form.field rule="switch_policies.politika:invert_match" name="invert_match" label="Ters eşleşme" id="ce-ters"
                                  hint="invert-match: “üyelerden hiçbirini taşımayan” anlamına gelir.">
                        <label class="checkbox">
                            <input type="checkbox" name="invert_match" id="ce-ters" value="1">
                            <span>Üyelerden hiçbirini taşımayanlar eşleşsin</span>
                        </label>
                    </x-form.field>
                </div>
                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>

        <x-ui.modal id="as-path-add" title="Yeni as-path">
            <form method="POST" action="{{ route('switches.as-paths.store', $switch) }}"
                  data-confirm="Bu işlem cihaza commit atar." data-confirm-title="Cihaza yaz">
                @csrf
                <div class="modal__body stack">
                    <x-form.field name="name" label="As-path adı" id="ae-name">
                        <x-form.input name="name" id="ae-name" mono required placeholder="musteri1-den" />
                    </x-form.field>

                    <x-form.field name="path" label="İfade" id="ae-expression"
                                  hint="Junos as-path düzenli ifadesi. “.* 65000” yolun sonu 65000 olanları, “65000 .*” başı 65000 olanları eşleştirir.">
                        <x-form.input name="path" id="ae-expression" mono required placeholder=".* 65000" />
                    </x-form.field>
                </div>
                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>

        @foreach ($communities as $community)
            <x-ui.modal :id="'community-'.$community->uuid" :title="$community->name.' düzenle'">
                <form method="POST" action="{{ route('switches.communities.update', [$switch, $community]) }}"
                      data-confirm="Bu işlem cihaza commit atar." data-confirm-title="Cihaza yaz"
                      data-confirm-verify="{{ $community->name }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$community" />
                    <div class="modal__body stack">
                        <x-form.field name="name" label="Community adı" :id="'cd-'.$community->uuid">
                            <x-form.input name="name" :id="'cd-'.$community->uuid"
                                          :value="$community->name" mono required readonly />
                        </x-form.field>

                        <x-form.field name="members" label="Üyeler" :id="'cm-'.$community->uuid"
                                      hint="Her satıra bir üye.">
                            <x-form.textarea name="members" :id="'cm-'.$community->uuid" rows="3"
                                             :value="implode(PHP_EOL, $community->members ?? [])" required />
                        </x-form.field>

                        <x-form.field rule="switch_policies.politika:invert_match" name="invert_match" label="Ters eşleşme" :id="'ci-'.$community->uuid"
                                      hint="invert-match: “üyelerden hiçbirini taşımayan”.">
                            <label class="checkbox">
                                <input type="checkbox" name="invert_match" id="ci-{{ $community->uuid }}" value="1"
                                       @checked($community->invert_match)>
                                <span>Üyelerden hiçbirini taşımayanlar eşleşsin</span>
                            </label>
                        </x-form.field>
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endforeach

        @foreach ($asPaths as $asPath)
            <x-ui.modal :id="'as-path-'.$asPath->uuid" :title="$asPath->name.' düzenle'">
                <form method="POST" action="{{ route('switches.as-paths.update', [$switch, $asPath]) }}"
                      data-confirm="Bu işlem cihaza commit atar." data-confirm-title="Cihaza yaz"
                      data-confirm-verify="{{ $asPath->name }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$asPath" />
                    <div class="modal__body stack">
                        {{-- Ad cihazda kimliktir; değiştirmek silip yeniden yaratmak demektir. --}}
                        <x-form.field name="name" label="As-path adı" :id="'ad-'.$asPath->uuid">
                            <x-form.input name="name" :id="'ad-'.$asPath->uuid" :value="$asPath->name"
                                          mono required readonly />
                        </x-form.field>

                        <x-form.field name="path" label="İfade" :id="'ap-'.$asPath->uuid"
                                      hint="Junos as-path düzenli ifadesi.">
                            <x-form.input name="path" :id="'ap-'.$asPath->uuid" :value="$asPath->path" mono required />
                        </x-form.field>
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endforeach

        <x-ui.modal id="policy-add" title="Yeni politika" wide>
            <form method="POST" action="{{ route('switches.policies.store', $switch) }}"
                  data-confirm="Bu işlem cihaza commit atar." data-confirm-title="Cihaza yaz">
                @csrf
                <div class="modal__body stack">
                    <x-form.field name="statement_name" label="Politika adı" id="pe-name"
                                  hint="BGP grubunun içeri alma alanı bu adı kullanır.">
                        <x-form.input name="statement_name" id="pe-name" mono required
                                      placeholder="prefixes-from-musteri1" />
                    </x-form.field>

                    {{--
                        İlk terim zorunludur: terimsiz politika cihazda sessizce
                        yok sayılıyor ("statement has no contents; ignored").
                    --}}
                    <x-policy.term-fields prefix="pe" :prefix-list-options="$prefixListOptions"
                                          :community-options="$communityOptions"
                                          :community-choices="$communityChoices"
                                          :as-path-choices="$asPathChoices"
                                          :match-type-options="$matchTypeOptions"
                                          :route-filter-type-options="$routeFilterTypeOptions"
                                          :action-options="$actionOptions"
                                          :value-operation-options="$valueOperationOptions"
                                          :community-operation-options="$communityOperationOptions" />

                    <details class="fieldgroup">
                        <summary class="fieldgroup__head">Politikanın varsayılan eylemi</summary>

                        <div class="fieldgroup__body">
                            <x-policy.default-fields prefix="pe"
                                                     :prefix-list-options="$prefixListOptions"
                                                     :community-choices="$communityChoices"
                                                     :as-path-choices="$asPathChoices"
                                                     :route-filter-type-options="$routeFilterTypeOptions"
                                                     :action-options="$actionOptions"
                                                     :value-operation-options="$valueOperationOptions"
                                                     :community-operation-options="$communityOperationOptions" />
                        </div>
                    </details>
                </div>
                <x-ui.modal-actions submit="Cihaza yaz" />
            </form>
        </x-ui.modal>

        {{-- Politikanın varsayılan davranışı: terimler değişmeden yazılır. --}}
        @foreach ($policies as $policy)
            <x-ui.modal :id="'pv-'.$policy->uuid" :title="$policy->name.' · varsayılan davranış'" wide>
                <form method="POST" action="{{ route('switches.policies.defaults', [$switch, $policy]) }}"
                      data-confirm="Bu satır politikanın son sözüdür: kaldırılırsa eşleşmeyen her duyuru bir sonraki politikaya bırakılır."
                      data-confirm-title="Cihaza yaz"
                      data-confirm-verify="{{ $policy->name }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$policy" />
                    <div class="modal__body stack">
                        <x-policy.default-fields :policy="$policy" :prefix="'pv'.$policy->uuid"
                                                 :prefix-list-options="$prefixListOptions"
                                                 :community-choices="$communityChoices"
                                                 :as-path-choices="$asPathChoices"
                                                 :route-filter-type-options="$routeFilterTypeOptions"
                                                 :action-options="$actionOptions"
                                                 :value-operation-options="$valueOperationOptions"
                                                 :community-operation-options="$communityOperationOptions" />
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endforeach

        {{-- Terim düzenleme: içerik ve SIRA burada değişir. --}}
        @foreach ($policies as $policy)
            @foreach ($policy->terms as $term)
                <x-ui.modal :id="'pd-'.$term->uuid" :title="$policy->name.' · '.$term->name" wide>
                    <form method="POST"
                          action="{{ route('switches.policies.terms.update', [$switch, $policy, $term]) }}"
                          data-confirm="Bu işlem cihaza commit atar. Sıra değişirse hangi prefixin kabul edileceği de değişir."
                          data-confirm-title="Cihaza yaz"
                          data-confirm-verify="{{ $term->name }}">
                        @csrf
                        @method('PUT')
                        <x-form.version :record="$term" />
                        <div class="modal__body stack">
                            <x-policy.term-fields :term="$term" :prefix="'pd'.$term->uuid"
                                                  :position-options="$positionOptions[$policy->uuid]"
                                                  :prefix-list-options="$prefixListOptions"
                                                  :community-options="$communityOptions"
                                                  :community-choices="$communityChoices"
                                                  :as-path-choices="$asPathChoices"
                                                  :match-type-options="$matchTypeOptions"
                                                  :route-filter-type-options="$routeFilterTypeOptions"
                                                  :action-options="$actionOptions"
                                                  :value-operation-options="$valueOperationOptions"
                                                  :community-operation-options="$communityOperationOptions" />
                        </div>
                        <x-ui.modal-actions submit="Cihaza yaz" />
                    </form>
                </x-ui.modal>
            @endforeach
        @endforeach

        @foreach ($policies as $policy)
            <x-ui.modal :id="'terim-ekle-'.$policy->uuid" :title="$policy->name.' · terim ekle'" wide>
                <form method="POST" action="{{ route('switches.policies.terms.store', [$switch, $policy]) }}"
                      data-confirm="Terim listenin SONUNA eklenir. Junos’ta ilk eşleşen terim kazanır."
                      data-confirm-title="Cihaza yaz">
                    @csrf
                    <div class="modal__body stack">
                        <x-policy.term-fields :prefix="'t'.$policy->uuid"
                                              :position-options="$positionOptions[$policy->uuid]"
                                              :prefix-list-options="$prefixListOptions"
                                              :community-options="$communityOptions"
                                              :community-choices="$communityChoices"
                                              :as-path-choices="$asPathChoices"
                                              :match-type-options="$matchTypeOptions"
                                              :route-filter-type-options="$routeFilterTypeOptions"
                                              :action-options="$actionOptions"
                                              :value-operation-options="$valueOperationOptions"
                                              :community-operation-options="$communityOperationOptions" />
                    </div>
                    <x-ui.modal-actions submit="Cihaza yaz" />
                </form>
            </x-ui.modal>
        @endforeach
    @endcan
</x-layout.app>
