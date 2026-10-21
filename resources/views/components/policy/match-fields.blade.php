{{--
    Politika koşulları ("from"). Terimin koşulu ve politikanın KENDİ koşulu
    aynı bileşendir; iki ekran için iki düzen yazılmaz.

    Hiçbir koşul verilmezse terim HER ŞEYE uyar — Junos'ta koşulsuz terim
    zinciri kapatan son terimdir.
--}}
<div class="stack">
    @if ($prefixListOptions !== [])
        <x-form.field :name="$field.'.prefix_lists'" label="Prefix liste (tam eşleşme)"
                      hint="from prefix-list: yalnızca listedeki prefixin kendisi eşleşir. Daha geniş eşleşme için aşağıdaki route-filter kullanılır.">
            <div class="choices">
                @foreach ($prefixListOptions as $list)
                    <label class="checkbox">
                        <input type="checkbox" name="{{ $field }}[prefix_lists][]" value="{{ $list }}"
                               @checked(in_array($list, $selectedPrefixLists, true))>
                        <span class="mono">{{ $list }}</span>
                    </label>
                @endforeach
            </div>
        </x-form.field>
    @endif

    {{--
        route-filter: adres + eşleşme türü. upto, through ve
        prefix-length-range bir DEĞER ister; exact, longer ve orlonger
        istemez. Cihaz ikisini de reddeder, panel önce söyler.
    --}}
    <x-form.field :name="$field.'.route_filters'" label="Route filtresi"
                  hint="Adres ve eşleşme türü. Değer alan türler: upto (/24), through (10.1.0.0/16), prefix-length-range (/24-/32).">
        <div class="stack stack--tight" data-repeat="{{ $prefix }}-route-filter">
            @foreach ($routeFilterRows as $row)
                <div class="row row--tight" data-repeat-row>
                    <x-form.input :name="$field.'[route_filters]['.$row['index'].'][address]'"
                                  :value="$row['address']" class="mono" placeholder="10.0.0.0/8" />
                    <x-form.select :name="$field.'[route_filters]['.$row['index'].'][match_type]'"
                                   :options="$routeFilterTypeOptions" :selected="$row['match_type'] ?: 'orlonger'" />
                    <x-form.input :name="$field.'[route_filters]['.$row['index'].'][value]'"
                                  :value="$row['value']" class="mono" placeholder="/24-/32" />
                    <button type="button" class="btn btn--ghost btn--sm" data-repeat-remove>Çıkar</button>
                </div>
            @endforeach

            <button type="button" class="btn btn--sm" data-repeat-add>Route filtresi ekle</button>
        </div>
    </x-form.field>

    <details class="fieldgroup">
        <summary class="fieldgroup__head">BGP koşulları (as-path, community)</summary>

        <div class="fieldgroup__body">
            @if ($asPathChoices === [])
                <x-ui.alert variant="info">
                    Bu switchte as-path tanımı yok. “As-path ekle” ile tanımlanan ifadeler burada listelenir;
                    cihazda tanımlı olmayan bir ada atıf commit’i düşürür.
                </x-ui.alert>
            @else
                <x-form.field :name="$field.'.as_paths'" label="AS yolu (as-path)"
                              hint="Duyurunun AS yolunu eşleştirir. Yalnızca cihazda tanımlı ifadeler seçilebilir.">
                    <div class="choices">
                        @foreach ($asPathChoices as $asPath)
                            <label class="checkbox">
                                <input type="checkbox" name="{{ $field }}[as_paths][]" value="{{ $asPath }}"
                                       @checked(in_array($asPath, $selectedAsPaths, true))>
                                <span class="mono">{{ $asPath }}</span>
                            </label>
                        @endforeach
                    </div>
                </x-form.field>
            @endif

            @if ($communityChoices !== [])
                <x-form.field :name="$field.'.communities'" label="Community"
                              hint="Duyurunun taşıdığı community. Yalnızca cihazda tanımlı adlar seçilebilir.">
                    <div class="choices">
                        @foreach ($communityChoices as $community)
                            <label class="checkbox">
                                <input type="checkbox" name="{{ $field }}[communities][]" value="{{ $community }}"
                                       @checked(in_array($community, $selectedCommunities, true))>
                                <span class="mono">{{ $community }}</span>
                            </label>
                        @endforeach
                    </div>
                </x-form.field>
            @endif
        </div>
    </details>

    <details class="fieldgroup">
        <summary class="fieldgroup__head">Diğer koşullar (protokol, komşu, arayüz, etiket)</summary>

        <div class="fieldgroup__body">
            @foreach ($textRows as $row)
                <x-form.field :name="$field.'.'.$row['key']" :label="$row['label']" :id="$row['id']"
                              :hint="$row['hint'].' Birden çok değer virgülle ayrılır.'">
                    <x-form.input :name="$row['name']" :id="$row['id']" :value="$row['value']" mono />
                </x-form.field>
            @endforeach
        </div>
    </details>
</div>
