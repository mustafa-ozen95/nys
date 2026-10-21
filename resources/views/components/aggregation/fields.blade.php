@props([
    'aggregate',
    'memberOptions' => [],
    'vlanOptions' => [],
    'untaggedOptions' => [],
    'modeOptions' => [],
    'lacpOptions' => [],
    'periodicOptions' => [],
    'linkSpeedOptions' => [],
    'maxMembers' => 8,

    // Alan kimliklerinin öneki; çağıran verir. Blade hesap yapmaz.
    'prefix',
])

<div class="modal__body stack">
    <x-form.field kural="switch_aggregation.aggregation:description" name="description" label="Açıklama" :id="$prefix.'-aciklama'">
        <x-form.input name="description" :id="$prefix.'-aciklama'" :value="$aggregate->description" />
    </x-form.field>

    <x-form.field kural="switch_aggregation.aggregation:enabled" name="enabled" label="Arayüz durumu" :id="$prefix.'-durum'">
        <label class="checkbox">
            <input type="hidden" name="enabled" value="0">
            <input type="checkbox" name="enabled" id="{{ $prefix }}-durum"
                   value="1" @checked($aggregate->is_enabled)>
            <span>Arayüz açık</span>
        </label>
    </x-form.field>

    {{--
        Üye yapılan port kendi mantıksal birimini KAYBEDER: cihaz
        "logical unit is not allowed on aggregated links" diyerek reddediyor,
        o yüzden birim aynı commit'te siliniyor. VLAN’ı da onunla gider.
    --}}
    <x-form.field name="members" :label="'Üye portlar (en fazla '.$maxMembers.')'"
                  hint="Üye yapılan port kendi VLAN’ını kaybeder ve trafiği ae üzerinden taşır.">
        @foreach ($memberOptions as $uuid => $label)
            <label class="checkbox">
                <input type="checkbox" name="members[]" value="{{ $uuid }}"
                       @checked($aggregate->members->contains('uuid', $uuid))>
                <span class="mono">{{ $label }}</span>
            </label>
        @endforeach
    </x-form.field>

    <x-form.field kural="switch_aggregation.aggregation:lacp_mode" name="lacp_mode" label="LACP" :id="$prefix.'-lacp'"
                  hint="Boş bırakılırsa LAG statiktir; protokol konuşulmaz.">
        <x-form.select name="lacp_mode" :id="$prefix.'-lacp'" :options="$lacpOptions"
                       :selected="$aggregate->lacp_mode?->value" />
    </x-form.field>

    {{-- Periyot yalnızca LACP açıkken anlamlıdır. --}}
    <x-form.field kural="switch_aggregation.aggregation:lacp_periodic" name="lacp_periodic" label="LACP periyodu" :id="$prefix.'-periyot'"
                  data-ae-lacp>
        <x-form.select name="lacp_periodic" :id="$prefix.'-periyot'" :options="$periodicOptions"
                       :selected="$aggregate->lacp_periodic?->value" />
    </x-form.field>

    <x-form.field kural="switch_aggregation.aggregation:minimum_links" name="minimum_links" label="En az bağlantı sayısı" :id="$prefix.'-min'"
                  hint="Üye sayısını geçemez; cihaz reddeder. Boş bırakılabilir.">
        <x-form.input name="minimum_links" :id="$prefix.'-min'" type="number"
                      :value="$aggregate->minimum_links" min="1" :max="$maxMembers" />
    </x-form.field>

    <x-form.field kural="switch_aggregation.aggregation:link_speed" name="link_speed" label="Bağlantı hızı" :id="$prefix.'-hiz'"
                  hint="Üyelerin beklenen hızı. Boş bırakılabilir.">
        <x-form.select name="link_speed" :id="$prefix.'-hiz'" :options="$linkSpeedOptions"
                       :selected="$aggregate->link_speed" />
    </x-form.field>

    <x-form.field kural="switch_aggregation.aggregation:port_mode" name="port_mode" label="Port modu" :id="$prefix.'-mod'">
        <x-form.select name="port_mode" :id="$prefix.'-mod'" :options="$modeOptions"
                       :selected="$aggregate->is_access ? 'access' : 'trunk'" data-ae-mod />
    </x-form.field>

    <x-form.field kural="switch_aggregation.aggregation:vlans" name="vlans" label="VLAN üyeliği"
                  hint="Access modunda tek VLAN seçilebilir.">
        @foreach ($vlanOptions as $uuid => $label)
            <label class="checkbox">
                <input type="checkbox" name="vlans[]" value="{{ $uuid }}"
                       @checked($aggregate->vlans->contains('uuid', $uuid))>
                <span class="mono">{{ $label }}</span>
            </label>
        @endforeach
    </x-form.field>

    {{-- Access portta tek üye zaten etiketsizdir; ayrıca sorulmaz. --}}
    <x-form.field kural="switch_aggregation.aggregation:untagged_vlan" name="untagged_vlan" label="Etiketsiz VLAN" :id="$prefix.'-etiketsiz'"
                  data-ae-trunk
                  hint="Seçilen VLAN üye listesinde de olmalı.">
        <x-form.select name="untagged_vlan" :id="$prefix.'-etiketsiz'" :options="$untaggedOptions"
                       :selected="$aggregate->vlans->firstWhere('pivot.is_untagged', true)?->uuid" />
    </x-form.field>
</div>
