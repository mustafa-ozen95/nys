@props(['vlan' => null, 'prefix' => 'vlan', 'ports' => []])

<x-form.field rule="switch_vlans.vlan:vlan_tag" name="vlan_tag" label="VLAN etiketi" :id="$prefix.'-tag'"
              hint="Boş bırakılabilir: cihaz etiketsiz VLAN’ı kabul eder. Boş kaydedilirse cihazdaki vlan-id satırı silinir.">
    <x-form.input name="vlan_tag" :id="$prefix.'-tag'" type="number" inputmode="numeric"
                  min="1" max="4094" :value="$vlan?->vlan_tag" mono />
</x-form.field>

@if ($vlan === null)
    <x-form.field name="name" label="Ad" :id="$prefix.'-name'"
                  hint="Cihazda VLAN’ın kimliği adıdır; sonradan değiştirilemez.">
        <x-form.input name="name" :id="$prefix.'-name'" placeholder="kamera" mono required />
    </x-form.field>
@else
    {{-- Ad cihazda VLAN'ın kimliği ve port üyelikleri ona bakar; değiştirmek
         VLAN'ı silip yeniden yaratmak demek olurdu. --}}
    <x-form.field rule="switch_vlans.vlan:name" name="name" label="Ad" :id="$prefix.'-name'"
                  hint="Ad değiştirilemez. Gerekiyorsa VLAN silinip yeniden eklenir.">
        <x-form.input name="name" :id="$prefix.'-name'" :value="$vlan->name" mono disabled />
    </x-form.field>
@endif

{{--
    Açıklama cihaza da yazılır: terminale düşen biri VLAN'ın ne işe yaradığını
    panele bakmadan görür. Boş bırakmak cihazdaki açıklamayı SİLER.
--}}
<x-form.field rule="switch_vlans.vlan:description" name="description" label="Açıklama" :id="$prefix.'-description'"
              hint="Cihaza da yazılır. Boş bırakılırsa cihazdaki açıklama silinir.">
    <x-form.input name="description" :id="$prefix.'-description'" :value="$vlan?->description"
                  placeholder="Kamera ağı" />
</x-form.field>

<x-form.field rule="switch_vlans.vlan:l3_interface" name="l3_interface" label="L3 arayüzü" :id="$prefix.'-l3'"
              hint="vlan.100 biçiminde. Zorunlu değil; verilirse cihazda arayüz de tanımlanır.">
    <x-form.input name="l3_interface" :id="$prefix.'-l3'" :value="$vlan?->l3_interface"
                  placeholder="vlan.100" mono />
</x-form.field>

<x-form.field rule="switch_vlans.vlan:ports" name="ports" label="Portlar" :id="$prefix.'-ports'"
              hint="İşaretlenen port cihazda bu VLAN’ın üyesi olur. Access portta tek üye olabilir; eski üyeliği değişir.">
    @forelse ($ports as $port)
        <label class="checkbox">
            <input type="checkbox" name="ports[]" value="{{ $port->uuid }}"
                   @checked($vlan?->ports->contains($port))>
            <span class="mono">{{ $port->name }}</span>
        </label>
    @empty
        <p class="text-sm text-muted">Bu switchte port kaydı yok.</p>
    @endforelse
</x-form.field>
