@props(['portOptions' => [], 'vlanOptions' => [], 'addressOptions' => [], 'address' => null, 'prefix' => 'ip'])

{{--
    Arayüz adı sorulmaz, seçimden türetilir: VLAN seçilirse VLAN'ın L3
    arayüzü, port seçilirse portun 0 numaralı birimi, loopback seçilirse
    lo0.0 — loopback'in ayrıca seçilecek bir nesnesi yoktur.

    Adres de serbest yazılmaz; Adresleme'de bu switche atanmış blokların
    kullanılabilir adresleri listelenir. Maske bloktan gelir.
--}}

<x-form.field rule="switch_ip_addresses.ip:attachment" name="attachment" label="Adres nereye bağlı?" :id="$prefix.'-connection'"
              hint="Arayüz adı bu seçime göre kendiliğinden belirlenir.">
    <div class="choices" data-ip-attachment>
        @foreach (['vlan' => 'VLAN arayüzü', 'port' => 'Port', 'loopback' => 'Loopback (lo0.0)'] as $deger => $etiket)
            <label class="checkbox">
                <input type="radio" name="attachment" value="{{ $deger }}"
                       @checked(old('attachment', $address?->attachment) === $deger)>
                <span>{{ $etiket }}</span>
            </label>
        @endforeach
    </div>
</x-form.field>

<x-form.field rule="switch_ip_addresses.ip:vlan_id" name="vlan_id" label="VLAN" :id="$prefix.'-vlan'" data-ip-field="vlan" hidden>
    <x-form.select name="vlan_id" :id="$prefix.'-vlan'" :options="$vlanOptions"
                   :selected="$address?->vlan?->uuid" />
</x-form.field>

<x-form.field rule="switch_ip_addresses.ip:switch_port_id" name="switch_port_id" label="Port" :id="$prefix.'-port'" data-ip-field="port" hidden>
    <x-form.select name="switch_port_id" :id="$prefix.'-port'" :options="$portOptions"
                   :selected="$address?->port?->uuid" />
</x-form.field>

<x-form.field rule="switch_ip_addresses.ip:address" name="address" label="Adres" :id="$prefix.'-address'"
              hint="Maske, adresin ait olduğu bloktan gelir.">
    @if ($addressOptions === [])
        <p class="text-sm text-muted">
            Adresleme’de bu switche atanmış blok yok. Önce
            <a href="{{ route('addressing.index') }}">Adresleme</a>’den blok atayın.
        </p>
    @else
        <x-form.select name="address" :id="$prefix.'-address'" :options="$addressOptions"
                       :selected="$address?->address" class="mono" />
    @endif
</x-form.field>
