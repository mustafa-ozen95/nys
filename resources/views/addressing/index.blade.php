<x-layout.app title="Adresleme">
    <div class="columns">
        {{--
            ADRES AĞACI.

            Satır YERİNDE açılır ve tanımlı alt ağların yanında boşluklar da
            yazar: yalnızca alt ağları gösteren bir listede bir /24'ün
            neresinin boş olduğunu görmek için boşlukları kafadan hesaplamak
            gerekirdi.

            Amaç çakışmayı önlemek: neyin kullanıldığı ve neyin boşta olduğu
            aynı ekranda görünmeli.
        --}}
        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">Bloklar</span>
                <span class="column__count">{{ count($tree) }}</span>

                {{--
                    Müşteri blokları ile altyapının kendi private blokları
                    (uplink bacakları, peering /30'ları) ayrı listelenir; aynı
                    listede durmaları ikisini de okunmaz hâle getirir.
                --}}
                <nav class="tabs tabs--inline" aria-label="Blok türü">
                    <a href="{{ route('addressing.index') }}"
                       class="tabs__link"
                       @if (! $infrastructure) aria-current="page" @endif>Müşteri</a>
                    <a href="{{ route('addressing.index', ['tur' => 'altyapi']) }}"
                       class="tabs__link"
                       @if ($infrastructure) aria-current="page" @endif>Altyapı</a>
                </nav>

                <span class="spacer"></span>

                @can('create', App\Models\AddressBlock::class)
                    <button type="button" class="btn btn--primary btn--sm" data-modal-open="block-add">
                        Yeni blok
                    </button>
                @endcan
            </div>

            <div class="column__body tree">
                @forelse ($tree as $dugum)
                    <x-dynamic-component component="net.block-node" :dugum="$dugum" :selected="$selected" />
                @empty
                    <x-ui.empty title="Blok yok"
                                :text="$infrastructure
                                    ? 'Uplink ve peering için ayrılmış blok yok. Yeni blok eklerken amacını “Uplink” ya da “BGP peering” seçin.'
                                    : 'İlk adres bloğunu ekleyin.'" />
                @endforelse
            </div>
        </div>

        @if ($selected)
            <aside class="detail">
                <div class="detail__head">
                    <div class="detail__title mono">{{ $selected->cidr }}</div>
                    <p class="detail__subtitle">{{ $selected->description ?: '—' }}</p>
                </div>

                <div class="detail__body">
                    <div class="detail__section">
                        <span class="eyebrow">Kullanım</span>

                        <div class="detail__block stack stack--tight">
                            <div class="row row--between">
                                <span class="facts__key">{{ $selected->is_announced ? 'Anons' : 'Atama' }}</span>
                                <x-net.assignment :block="$selected" />
                            </div>

                            @if ($usage['subnets'] > 0)
                                <div class="row row--between">
                                    <span class="facts__key">Alt ağlara ayrılan</span>
                                    <x-ui.meter :value="$usage['percent']" metric="bandwidth" />
                                </div>
                            @endif
                        </div>

                        @if ($usage['subnets'] > 0)
                            <x-ui.facts class="detail__block" :rows="[
                                'Alt ağ' => $usage['subnets'],
                                'Route edilen alt ağ' => $usage['assigned'],
                                {{-- IPv6'da adres sayısı sayılamaz; oran yine de anlamlıdır. --}}
                                'Ayrılan adres' => $usage['total'] === null
                                    ? $usage['percent'].'%'
                                    : number_format($usage['allocated'], 0, ',', '.').' / '.number_format($usage['total'], 0, ',', '.'),
                            ]" />
                        @endif
                    </div>

                    <div class="detail__section">
                        <span class="eyebrow">Adres aralığı</span>
                        <x-ui.facts class="detail__block" :rows="$facts" />
                    </div>

                    {{--
                        HANGİ ADRES KİMDE.

                        Ağaç ağ düzeyini anlatıyor ("bu /24'ün neresi boş");
                        burası host düzeyi ("bu /29'un hangi adresi boşta").
                        Kaynak tek tablo değil: adres switch arayüzünde,
                        sunucuda ya da BGP komşusunda duruyor olabilir.

                        Geniş blokta liste basılmaz — bir /16'yı tek tek
                        yazmak ekranı da tarayıcıyı da kilitler.
                    --}}
                    @if ($hosts && ! $hosts['sinirli'])
                        <div class="detail__section">
                            <span class="eyebrow">Adresler</span>

                            <p class="detail__block text-sm text-muted">
                                {{ $hosts['dolu'] }} / {{ $hosts['toplam'] }} kullanımda
                            </p>

                            <ul class="address-list">
                                @foreach ($hosts['adresler'] as $adres)
                                    <li @class(['address-list__row', 'address-list__row--full' => $adres['sahip'] !== null])>
                                        <span class="mono">{{ $adres['adres'] }}</span>

                                        @if ($adres['sahip'] !== null)
                                            <span class="text-sm truncate">{{ $adres['sahip'] }}</span>
                                        @else
                                            <span class="text-sm text-muted">boşta</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="detail__section">
                        <span class="eyebrow">Açıklama</span>

                        @if (filled($selected->notes))
                            <p class="block-note">{{ $selected->notes }}</p>
                        @else
                            <p class="detail__block text-muted text-sm">Açıklama girilmemiş.</p>
                        @endif
                    </div>
                </div>

                <div class="detail__foot">
                    @can('update', $selected)
                        <button type="button" class="btn btn--sm" data-modal-open="assignment-edit">
                            Atamayı düzenle
                        </button>
                    @endcan

                    @can('create', App\Models\AddressBlock::class)
                        <button type="button" class="btn btn--primary btn--sm" data-modal-open="alt-network-add">
                            Alt ağ ekle
                        </button>
                    @endcan

                    @can('delete', $selected)
                        <x-ui.delete-form :action="route('addressing.destroy', $selected)"
                                          confirm="{{ $selected->cidr }} ve altındaki her şey silinecek."
                                          title="Bloğu sil"
                                          verify="{{ $selected->network }}"
                                          label="Bloğu sil" />
                    @endcan
                </div>
            </aside>
        @endif
    </div>

    @can('create', App\Models\AddressBlock::class)
        <x-ui.modal id="block-add" title="Yeni adres bloğu" wide>
            <form method="POST" action="{{ route('addressing.store') }}" data-assignment-form
                  data-confirm="Yeni blok eklenecek."
                  data-confirm-title="Blok ekle">
                @csrf

                <div class="modal__body form-grid">
                    <x-form.field name="network" label="Ağ adresi" hint="Maskenin ağ adresi olmalı.">
                        <x-form.input name="network" placeholder="198.18.21.0" mono required />
                    </x-form.field>

                    <x-form.field name="prefix_length" label="Maske">
                        <x-form.select name="prefix_length" :options="$maskOptions" :selected="24" />
                    </x-form.field>

                    <x-form.field name="purpose" label="Bu blok ne için?">
                        <x-form.select name="purpose" :options="$purposeOptions"
                                       :selected="$infrastructure ? 'peering' : 'customer'" />
                    </x-form.field>

                    <x-form.field name="description" label="Başlık" hint="Listede görünen kısa ad.">
                        <x-form.input name="description" placeholder="Kamera ağı" />
                    </x-form.field>

                    <x-addressing.assignment-fields :core-switch-options="$coreSwitchOptions"
                                                    :access-switch-options="$accessSwitchOptions" :type-options="$typeOptions"
                                                   :prefix-list-options="$prefixListOptions" :uplink-options="$uplinkOptions" />

                    <x-form.field name="notes" label="Açıklama" class="field--wide">
                        <x-form.textarea name="notes" />
                    </x-form.field>
                </div>

                <x-ui.modal-actions submit="Ekle" />
            </form>
        </x-ui.modal>
    @endcan

    @if ($selected)
        @can('create', App\Models\AddressBlock::class)
            <x-ui.modal id="alt-network-add" :title="$selected->cidr.' içinde alt ağ'" wide>
                <form method="POST" action="{{ route('addressing.subnets.store', $selected) }}" data-assignment-form
                      data-confirm="Alt blok eklenecek."
                      data-confirm-title="Alt blok ekle">
                    @csrf

                    <div class="modal__body form-grid">
                        <x-form.field name="prefix_length" label="Maske" :id="'alt-network-mask'">
                            <x-form.select name="prefix_length" :id="'alt-network-mask'" :options="$subnetMaskOptions"
                                           data-slot-mask
                                           data-slot-end="{{ route('ajax.addressing.slots', $selected) }}" />
                        </x-form.field>

                        <x-form.field name="network" label="Boş yuva" :id="'alt-network-slot'"
                                      hint="Yalnızca boştaki yuvalar listelenir.">
                            <select class="select input--mono" name="network" id="alt-network-slot" data-slot-selection required>
                                <option value="">Önce maske seçin</option>
                            </select>
                        </x-form.field>

                        <x-form.field name="description" label="Başlık" :id="'alt-network-header'"
                                      hint="Listede görünen kısa ad.">
                            <x-form.input name="description" id="alt-network-header" />
                        </x-form.field>

                        <x-addressing.assignment-fields :core-switch-options="$coreSwitchOptions"
                                                    :access-switch-options="$accessSwitchOptions" :type-options="$typeOptions"
                                                        :prefix-list-options="$prefixListOptions" :uplink-options="$uplinkOptions" prefix="alt-ag" />

                        <x-form.field name="notes" label="Açıklama" :id="'alt-network-note'" class="field--wide">
                            <x-form.textarea name="notes" id="alt-network-note" />
                        </x-form.field>
                    </div>

                    <x-ui.modal-actions submit="Ekle" />
                </form>
            </x-ui.modal>
        @endcan

        @can('update', $selected)
            <x-ui.modal id="assignment-edit" :title="$selected->cidr.' ataması'" wide>
                <form method="POST" action="{{ route('addressing.update', $selected) }}"
                      data-assignment-form data-assignment-block="{{ $selected->cidr }}"
                      data-confirm="{{ $selected->cidr }} bloğunun ataması değişecek."
                      data-confirm-title="Atamayı düzenle"
                      data-confirm-verify="{{ $selected->cidr }}">
                    @csrf
                    @method('PUT')
                    <x-form.version :record="$selected" />

                    <div class="modal__body">
                        <x-form.field name="purpose" label="Bu blok ne için?" :id="'assignment-purpose'">
                            <x-form.select name="purpose" id="assignment-purpose" :options="$purposeOptions"
                                           :selected="$selected->purpose->value" />
                        </x-form.field>

                        <x-form.field name="description" label="Başlık" :id="'assignment-header'"
                                      hint="Listede görünen kısa ad.">
                            <x-form.input name="description" id="assignment-header" :value="$selected->description" />
                        </x-form.field>

                        <x-addressing.assignment-fields :core-switch-options="$coreSwitchOptions"
                                                    :access-switch-options="$accessSwitchOptions" :type-options="$typeOptions"
                                                        :prefix-list-options="$prefixListOptions" :uplink-options="$uplinkOptions" :block="$selected" prefix="atama" />

                        <x-form.field name="notes" label="Açıklama" :id="'assignment-note'" class="field--wide">
                            <x-form.textarea name="notes" id="assignment-note" :value="$selected->notes" />
                        </x-form.field>
                    </div>

                    <x-ui.modal-actions submit="Kaydet" />
                </form>
            </x-ui.modal>
        @endcan
    @endif
</x-layout.app>
