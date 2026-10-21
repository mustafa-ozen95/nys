{{--
    Politika eylemleri ("then"). İki tür eylem vardır: zinciri bitiren
    sonlandırıcı eylem (accept/reject/next) ve rotayı değiştiren eylemler
    (local-preference, community, as-path-prepend...). İkisi birlikte
    kullanılabilir.
--}}
<div class="stack">
    <x-form.field :name="$actionName" label="Sonuç" :id="$prefix.'-sonuc'"
                  hint="Zinciri bitiren eylem. “Sonraki politikaya geç” değerlendirmeyi devreder.">
        <x-form.select :name="$actionName" :id="$prefix.'-sonuc'" :options="$actionOptions"
                       :selected="$actionValue" />
    </x-form.field>

    <details class="fieldgroup">
        <summary class="fieldgroup__head">BGP nitelikleri (local-preference, metrik, AS yolu)</summary>

        <div class="fieldgroup__body">
            @foreach ($valueRows as $row)
                <x-form.field :name="$field.'.'.$row['key'].'.value'" :label="$row['label']" :id="$row['id']"
                              :hint="$row['hint'].' Boş bırakılırsa yazılmaz.'">
                    <div class="row row--tight">
                        <x-form.select :name="$row['operationName']" :options="$valueOperationOptions"
                                       :selected="$row['operation'] ?: 'set'" />
                        <x-form.input type="number" :name="$row['valueName']" :id="$row['id']"
                                      :value="$row['value']" />
                    </div>
                </x-form.field>
            @endforeach

            @foreach ($textRows as $row)
                <x-form.field :name="$field.'.'.$row['key']" :label="$row['label']" :id="$row['id']"
                              :hint="$row['hint']">
                    <x-form.input :name="$row['name']" :id="$row['id']" :value="$row['value']" mono />
                </x-form.field>
            @endforeach
        </div>
    </details>

    @if ($communityChoices !== [])
        {{--
            "community add/set/delete". Aynı community’ye tek terimde birden
            çok işlem uygulanamaz; sunucu da reddeder.
        --}}
        <x-form.field :name="$field.'.communities'" label="Community işlemleri"
                      hint="Yalnızca cihazda tanımlı community’ler seçilebilir; tanımsız ada atıf commit’i düşürür.">
            <div class="stack stack--tight" data-repeat="{{ $prefix }}-community">
                @foreach ($communityRows as $row)
                    <div class="row row--tight" data-repeat-row>
                        <x-form.select :name="$field.'[communities]['.$row['index'].'][operation]'"
                                       :options="$communityOperationOptions" :selected="$row['operation'] ?: 'add'" />
                        <x-form.select :name="$field.'[communities]['.$row['index'].'][name]'"
                                       :options="['' => 'Yok'] + $communityChoices" :selected="$row['name']" />
                        <button type="button" class="btn btn--ghost btn--sm" data-repeat-remove>Çıkar</button>
                    </div>
                @endforeach

                <button type="button" class="btn btn--sm" data-repeat-add>Community işlemi ekle</button>
            </div>
        </x-form.field>
    @endif
</div>
