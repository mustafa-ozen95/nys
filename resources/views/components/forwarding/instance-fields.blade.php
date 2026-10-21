@props(['prefix', 'instance' => null, 'typeOptions' => []])

{{--
    Yönlendirme tablosu alanları. Ekleme ve düzenleme AYNI alanları kullanır;
    iki kopya olsaydı biri değiştiğinde öteki sessizce ayrışırdı.

    Ad cihazda KİMLİKTİR: düzenlemede gösterilir ama değiştirilemez.
--}}
@if ($instance === null)
    <x-form.field name="name" label="Tablo adı" :id="$prefix.'-ad'"
                  hint="Harf, rakam, nokta, tire ve alt çizgi. Firewall terimi bu adla atıf yapar.">
        <x-form.input name="name" :id="$prefix.'-ad'" mono required placeholder="musteri" />
    </x-form.field>
@else
    <x-ui.facts :rows="['Tablo adı' => $instance->name]" />
@endif

<x-form.field kural="switch_forwarding.ornek:instance_type" name="instance_type" label="Tür" :id="$prefix.'-tur'"
              hint="Filtre tabanlı yönlendirme için “forwarding” kullanılır. Listede yoksa cihaz onu kabul etmiyor demektir.">
    <x-form.select name="instance_type" :id="$prefix.'-tur'"
                   :options="$typeOptions" :selected="$instance?->instance_type?->value" />
</x-form.field>

<x-form.field kural="switch_forwarding.ornek:description" name="description" label="Açıklama" :id="$prefix.'-aciklama'"
              hint="Boş bırakılırsa cihazdaki açıklama silinir.">
    <x-form.input name="description" :id="$prefix.'-aciklama'" :value="$instance?->description" />
</x-form.field>
