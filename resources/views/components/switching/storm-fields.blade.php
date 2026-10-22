@props(['control' => null, 'prefix' => 'firtina'])

{{--
    Eşik ya YÜZDE ya KBPS'dir; ikisi birlikte gönderilemez ve kural sunucuda
    da vardır. Yüzde arayüz hızına oranlıdır, kbps mutlaktır.
--}}
<x-form.field rule="switch_secure_access.firtina:level" name="level" label="Eşik (yüzde)" :id="$prefix.'-percent'"
              hint="Arayüz hızının yüzde kaçı. Yaygın başlangıç: %80.">
    <x-form.input type="number" name="level" :id="$prefix.'-percent'" :value="$control?->level"
                  min="1" max="100" />
</x-form.field>

<x-form.field rule="switch_secure_access.firtina:bandwidth" name="bandwidth" label="Eşik (kbps)" :id="$prefix.'-kbps'"
              hint="Yüzde yerine mutlak sınır. İkisinden yalnızca biri doldurulur.">
    <x-form.input type="number" name="bandwidth" :id="$prefix.'-kbps'" :value="$control?->bandwidth" min="1" />
</x-form.field>

{{--
    Denetim DIŞINDA bırakılan trafik türleri. Örneğin çoklu gönderim ağır
    kullanılan bir ağda "no-multicast" seçilir; yayın yine sınırlanır.
--}}
<x-form.field rule="switch_secure_access.firtina:exemptions" name="exemptions" label="Denetim dışında bırak" :id="$prefix.'-exempt'"
              hint="Seçilen tür eşikten muaf tutulur; diğerleri sınırlanmaya devam eder.">
    <div class="choices">
        @foreach ([
            'no_broadcast' => 'Yayın (broadcast)',
            'no_unknown_unicast' => 'Bilinmeyen tekil (unknown unicast)',
            'no_multicast' => 'Çoklu gönderim (multicast)',
            'no_registered_multicast' => 'Kayıtlı çoklu gönderim',
            'no_unregistered_multicast' => 'Kayıtsız çoklu gönderim',
        ] as $alan => $etiket)
            <label class="checkbox">
                <input type="hidden" name="{{ $alan }}" value="0">
                <input type="checkbox" name="{{ $alan }}" value="1" @checked($control?->{$alan})>
                <span>{{ $etiket }}</span>
            </label>
        @endforeach
    </div>
</x-form.field>

@if ($control?->preserved_lines)
    <x-ui.alert variant="info">
        Bu ayarda panelin göstermediği {{ count($control->preserved_lines) }} satır var
        ({{ implode(', ', $control->preserved_lines) }}). Kaydettiğinizde cihazda olduğu gibi korunurlar.
    </x-ui.alert>
@endif
