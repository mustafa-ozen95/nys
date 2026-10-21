@props([
    'analyzer' => null,
    'prefix' => 'aynalama',
    'sourceOptions' => [],
    'outputOptions' => [],
    'outputVlanOptions' => [],
    'vlanOptions' => [],
    'lossPriorityOptions' => [],
])

{{--
    Aynalama oturumunun alanları.

    Ad cihazda KİMLİKTİR: firewall terimleri de aynalamaya adıyla atıf yapar
    ("then analyzer <ad>"). Bu yüzden düzenlemede salt okunur gösterilir ve
    değişmiş bir ad sunucuda da reddedilir.
--}}
@if ($analyzer === null)
    <x-form.field kural="switch_mirroring.analyzer:name" name="name" label="Aynalama adı" :id="$prefix.'-ad'"
                  hint="Harfle başlar. Firewall terimleri aynalamaya bu adla atıf yapar.">
        <x-form.input name="name" :id="$prefix.'-ad'" mono />
    </x-form.field>
@else
    <x-form.field kural="switch_mirroring.analyzer:name" name="name" label="Aynalama adı" :id="$prefix.'-ad'"
                  hint="Ad cihazda kimliktir ve değiştirilemez; yeni ad için kayıt silinip yeniden eklenir.">
        <x-form.input name="name" :id="$prefix.'-ad'" :value="$analyzer->name" mono readonly />
    </x-form.field>
@endif

{{--
    ÇIKIŞ HEDEFİ TEKTİR: ya arayüz ya VLAN. İkisi birlikte gönderilirse
    sunucu reddeder (Junos kuralı).

    Çıkış listesinde yalnızca anahtarlama modundaki portlar vardır; cihaz
    aksini reddediyor: "Analyzer output interface ge-0/0/21.0 should be
    configured with family ethernet-switching" (test1'de ölçüldü).
--}}
<x-form.field kural="switch_mirroring.analyzer:output_interface" name="output_interface" label="Çıkış arayüzü" :id="$prefix.'-cikis'"
              hint="Aynalanan trafik buraya kopyalanır. Yalnızca anahtarlama ailesindeki portlar listelenir.">
    <x-form.select name="output_interface" :id="$prefix.'-cikis'"
                   :options="$outputOptions"
                   :selected="$analyzer?->output_interface" />
</x-form.field>

<x-form.field kural="switch_mirroring.analyzer:output_vlan" name="output_vlan" label="Çıkış VLAN’ı" :id="$prefix.'-cikis-vlan'"
              hint="Uzak aynalama: trafik bir VLAN’a kopyalanır. Arayüz ile birlikte verilemez.">
    <x-form.select name="output_vlan" :id="$prefix.'-cikis-vlan'"
                   :options="$outputVlanOptions"
                   :selected="$analyzer?->output_vlan" />
</x-form.field>

<x-form.checkbox-list name="ingress_interfaces" label="Giriş yönü kaynak portları"
                      :id="$prefix.'-giris'"
                      :options="$sourceOptions"
                      :selected="$analyzer?->ingress_interfaces ?? []"
                      hint="Porta GİREN trafik kopyalanır. Kaynak port çıkış portu olamaz."
                      empty="Bu switchte port yok." />

<x-form.checkbox-list name="egress_interfaces" label="Çıkış yönü kaynak portları"
                      :id="$prefix.'-cikan'"
                      :options="$sourceOptions"
                      :selected="$analyzer?->egress_interfaces ?? []"
                      hint="Porttan ÇIKAN trafik kopyalanır."
                      empty="Bu switchte port yok." />

<x-form.checkbox-list name="ingress_vlans" label="Giriş yönü kaynak VLAN’ları"
                      :id="$prefix.'-giris-vlan'"
                      :options="$vlanOptions"
                      :selected="$analyzer?->ingress_vlans ?? []"
                      hint="VLAN’a giren trafiğin tamamı kopyalanır."
                      empty="Bu switchte VLAN yok." />

<x-form.checkbox-list name="egress_vlans" label="Çıkış yönü kaynak VLAN’ları"
                      :id="$prefix.'-cikan-vlan'"
                      :options="$vlanOptions"
                      :selected="$analyzer?->egress_vlans ?? []"
                      hint="VLAN’dan çıkan trafiğin tamamı kopyalanır."
                      empty="Bu switchte VLAN yok." />

<x-form.field kural="switch_mirroring.analyzer:ratio" name="ratio" label="Örnekleme oranı" :id="$prefix.'-oran'"
              hint="Kaç pakette bir kopyalansın. Boş bırakılırsa cihazın varsayılanı geçerlidir.">
    <x-form.input type="number" name="ratio" :id="$prefix.'-oran'" :value="$analyzer?->ratio" min="1" />
</x-form.field>

<x-form.field kural="switch_mirroring.analyzer:loss_priority" name="loss_priority" label="Kayıp önceliği" :id="$prefix.'-kayip'"
              hint="Aynalanan kopyanın önceliği. Yüksek öncelik, tıkanıklıkta gerçek trafiğin önüne geçebilir.">
    <x-form.select name="loss_priority" :id="$prefix.'-kayip'"
                   :options="$lossPriorityOptions"
                   :selected="$analyzer?->loss_priority" />
</x-form.field>

@if ($analyzer?->preserved_lines)
    <x-ui.alert variant="info">
        Bu aynalamada panelin göstermediği {{ count($analyzer->preserved_lines) }} satır var
        ({{ implode(', ', $analyzer->preserved_lines) }}). Kaydettiğinizde cihazda olduğu gibi korunurlar.
    </x-ui.alert>
@endif
