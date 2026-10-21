{{--
    Policer: terimin "then policer <ad>" ile çağırdığı hız sınırı.

    Cihazda ölçüldü (test1, commit check):
      firewall policer P { if-exceeding { bandwidth-limit 1m; burst-size-limit 15k; } then { discard; } }

    Mutlak hız ile yüzde birbirini dışlar; sunucu da reddeder.
--}}
@props([
    'policer' => null,
    'prefix' => 'policer',
    'lossPriorityOptions' => [],
    'forwardingClassOptions' => [],
])

<x-form.field name="name" label="Policer adı" :id="$prefix.'-ad'"
              hint="Terimler bu adla çağırır; ad değişirse çağıran terimler de güncellenmelidir.">
    <x-form.input name="name" :id="$prefix.'-ad'" :value="$policer?->name" mono required placeholder="musteri-10m" />
</x-form.field>

<x-form.field kural="switch_firewall.policer:bandwidth_limit" name="bandwidth_limit" label="Hız sınırı" :id="$prefix.'-hiz'"
              hint="Birimiyle yazılır: 1m, 500k, 8g ya da düz bit/s. Yüzde ile birlikte verilemez.">
    <x-form.input name="bandwidth_limit" :id="$prefix.'-hiz'" :value="$policer?->bandwidth_limit"
                  mono placeholder="10m" />
</x-form.field>

<x-form.field kural="switch_firewall.policer:bandwidth_percent" name="bandwidth_percent" label="Hız yüzdesi" :id="$prefix.'-yuzde'"
              hint="Arayüz hızının yüzdesi olarak sınır. Mutlak hız verildiyse boş bırakılır.">
    <x-form.input type="number" name="bandwidth_percent" :id="$prefix.'-yuzde'"
                  :value="$policer?->bandwidth_percent" min="1" max="100" />
</x-form.field>

<x-form.field kural="switch_firewall.policer:burst_size_limit" name="burst_size_limit" label="Burst boyu" :id="$prefix.'-burst'"
              hint="Anlık taşmaya izin verilen bayt: 15k, 1m. Genelde hızın ~5 katı bayt seçilir.">
    <x-form.input name="burst_size_limit" :id="$prefix.'-burst'" :value="$policer?->burst_size_limit"
                  mono placeholder="15k" />
</x-form.field>

<x-form.field kural="switch_firewall.policer:filter_specific" name="filter_specific" label="Sayaç kapsamı" :id="$prefix.'-fs'">
    <label class="checkbox">
        <input type="hidden" name="filter_specific" value="0">
        <input type="checkbox" name="filter_specific" id="{{ $prefix }}-fs" value="1"
               @checked($policer?->filter_specific)>
        <span>Filtre başına ayrı sayaç (filter-specific)</span>
    </label>
</x-form.field>

{{--
    Eylemsiz policer paketi ölçer ama hiçbir şey yapmaz; kullanıcı hız
    sınırladığını sanır. Sunucu en az birini ister.
--}}
<x-form.field kural="switch_firewall.policer:is_discard" name="is_discard" label="Sınırı aşan pakete" :id="$prefix.'-discard'"
              hint="En az biri seçilmeli; yoksa policer ölçer ama hiçbir şey yapmaz.">
    <label class="checkbox">
        <input type="hidden" name="is_discard" value="0">
        <input type="checkbox" name="is_discard" id="{{ $prefix }}-discard" value="1"
               @checked($policer?->is_discard)>
        <span>Düşür (discard)</span>
    </label>
</x-form.field>

<x-form.field kural="switch_firewall.policer:forwarding_class" name="forwarding_class" label="İletim sınıfına al" :id="$prefix.'-sinif'">
    <x-form.select name="forwarding_class" :id="$prefix.'-sinif'" :options="$forwardingClassOptions"
                   :selected="$policer?->forwarding_class" />
</x-form.field>

<x-form.field kural="switch_firewall.policer:loss_priority" name="loss_priority" label="Kayıp önceliğini yükselt" :id="$prefix.'-kayip'"
              hint="Düşürmek yerine tıkanıklıkta önce bu paketleri feda eder.">
    <x-form.select name="loss_priority" :id="$prefix.'-kayip'" :options="$lossPriorityOptions"
                   :selected="$policer?->loss_priority" />
</x-form.field>

@if ($policer?->preserved_lines)
    <x-ui.alert variant="info">
        Bu policer'da panelin göstermediği {{ count($policer->preserved_lines) }} satır var
        ({{ implode(', ', $policer->preserved_lines) }}). Kaydettiğinizde cihazda olduğu gibi korunurlar.
    </x-ui.alert>
@endif
