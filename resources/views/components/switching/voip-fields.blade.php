@props([
    'voipInterface' => null,
    'vlanOptions' => [],
    'forwardingClasses' => [],
    'prefix' => 'voip',
])

{{--
    Ses VLAN'ı ile iletim sınıfı. En az biri verilmelidir; ikisi de boşsa
    cihazda hiçbir işe yaramayan boş bir arayüz düğümü kalırdı. Kural
    sunucuda da vardır (required_without).
--}}
<x-form.field kural="switch_voip.voip:vlan_name" name="vlan_name" label="Ses VLAN’ı" :id="$prefix.'-vlan'"
              hint="Telefonun ETİKETLİ olarak kullanacağı VLAN. Portun kendi (etiketsiz) VLAN’ından farklı olmalıdır: bilgisayar etiketsiz, telefon etiketli gider.">
    <x-form.select name="vlan_name" :id="$prefix.'-vlan'" :options="$vlanOptions"
                   :selected="$voipInterface?->vlan_name" />
</x-form.field>

<x-form.field kural="switch_voip.voip:forwarding_class" name="forwarding_class" label="İletim sınıfı" :id="$prefix.'-sinif'"
              hint="Ses trafiğinin hangi kuyruğa gireceği. Boş bırakılırsa cihaz kendi sınıfını uygular.">
    <x-form.select name="forwarding_class" :id="$prefix.'-sinif'" :options="$forwardingClasses"
                   :selected="$voipInterface?->forwarding_class" />
</x-form.field>

@if ($voipInterface?->preserved_lines)
    <x-ui.alert variant="info">
        Bu ayarda panelin göstermediği {{ count($voipInterface->preserved_lines) }} satır var
        ({{ implode(', ', $voipInterface->preserved_lines) }}). Kaydettiğinizde cihazda olduğu gibi korunurlar.
    </x-ui.alert>
@endif
