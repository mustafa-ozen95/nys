{{--
    Form adım adım açılır: bir alan, kendisinden önceki soru cevaplanmadan
    görünmez.

      Yöntem  →  Omurga switch  →  yönteme özel alanlar
                                     VLAN/Port : "omurgada" tiki ya da
                                                 erişim switchi → hedef
                                     Static    : kaynak → IP
                                     Prefix    : omurga → liste

    Hepsini birden göstermek, kullanıcıya o an anlamsız olan alanları
    doldurtur (ör. VLAN seçiliyken "Uplink IP" görünmesi).

    Yönteme bağlı alanlar tek blokta toplanır ve blok tamamen gizlenir;
    ayrı ayrı dururlarsa biri açık kalır.

    Anons ayrı bir alan değildir: blok omurgadaki bir prefix list'e girmişse
    anons ediliyordur. ASN burada sorulmaz — anons e-postasının kendi ASN
    alanı var.
--}}
<x-form.field name="connection_type" label="Yöntem" :id="$prefix.'-type'"
              hint="Bloğun nasıl bağlanacağı; alanlar buna göre açılır.">
    <x-form.select name="connection_type" :id="$prefix.'-type'" :options="$typeOptions"
                   :selected="$block?->connection_type?->value" data-assignment-type />
</x-form.field>

{{-- Omurga switch yöntem seçilene kadar sorulmaz. --}}
<x-form.field name="core_switch_id" label="Omurga switch" :id="$prefix.'-backbone'"
              data-assignment-step="type" hidden
              hint="Prefix list için zorunlu; BGP omurgada yönetilir.">
    <x-form.select name="core_switch_id" :id="$prefix.'-backbone'" :options="$coreSwitchOptions"
                   :selected="$block?->core_switch_id" data-assignment-backbone />
</x-form.field>

{{-- VLAN ve port yalnızca erişim switchinde olmak zorunda değil; omurgada da
     olabilir. Erişim switchi boş bırakılırsa hedefler omurgadan listelenir. --}}
<div class="form-grid field--wide" data-assignment-field="vlan port"
     data-assignment-target-end="{{ route('ajax.switches.targets', ['switch' => '__id__']) }}" hidden>
    {{-- İşaretlenirse erişim switchi hiç sorulmaz; hedefler omurgadan gelir. --}}
    <x-form.field name="omurgada" label="Nerede" :id="$prefix.'-in-backbone'">
        <label class="checkbox">
            <input type="checkbox" id="{{ $prefix }}-in-backbone" data-assignment-backbone
                   @checked($block !== null && $block->access_switch_id === null)>
            <span>Omurga switchte</span>
        </label>
    </x-form.field>

    <x-form.field name="access_switch_id" label="Erişim switch" :id="$prefix.'-access'"
                  data-assignment-step="accessAsked" hidden>
        <x-form.select name="access_switch_id" :id="$prefix.'-access'" :options="$accessSwitchOptions"
                       :selected="$block?->access_switch_id" data-assignment-access />
    </x-form.field>

    {{-- Hedef, erişim switchi seçilmeden sorulmaz: liste ondan doldurulur. --}}
    <x-form.field name="vlan_id" label="VLAN" :id="$prefix.'-vlan'"
                  data-assignment-target="vlan" data-assignment-step="targetSwitch" hidden>
        <select class="select" name="vlan_id" id="{{ $prefix }}-vlan"
                data-assignment-selection="vlan" data-assignment-selected="{{ $block?->vlan?->uuid }}" disabled>
            <option value="">Seçiniz</option>
        </select>
    </x-form.field>

    <x-form.field name="port_name" label="Port" :id="$prefix.'-port'"
                  data-assignment-target="port" data-assignment-step="targetSwitch" hidden>
        <select class="select" name="port_name" id="{{ $prefix }}-port"
                data-assignment-selection="port" data-assignment-selected="{{ $block?->port_name }}" disabled>
            <option value="">Seçiniz</option>
        </select>
    </x-form.field>
</div>

{{-- Sonraki atlama ya envanterdeki bir switchin uplink'idir ya elle girilir;
     müşteri switchleri envanterde olmadığı için elle giriş şart. --}}
<div class="form-grid field--wide" data-assignment-field="next_hop" hidden>
    <x-form.field name="next_hop_source" label="Sonraki atlama" :id="$prefix.'-hop-source'">
        <label class="checkbox">
            <input type="radio" name="next_hop_source" value="switch" data-assignment-skip
                   @checked($block?->next_hop !== null && ! $manualNextHop)>
            <span>Switch uplink’inden seç</span>
        </label>
        <label class="checkbox">
            <input type="radio" name="next_hop_source" value="manual" data-assignment-skip
                   @checked($manualNextHop)>
            <span>Elle gir</span>
        </label>
    </x-form.field>

    <x-form.field name="next_hop_switch" label="Uplink IP" :id="$prefix.'-hop-switch'"
                  data-assignment-skip-field="switch" data-assignment-step="skip" hidden
                  hint="Envanterdeki switchlerin uplink adresleri.">
        <x-form.select name="next_hop_switch" :id="$prefix.'-hop-switch'"
                       :options="$uplinkOptions" :selected="$manualNextHop ? null : $block?->next_hop" />
    </x-form.field>

    <x-form.field name="next_hop" label="Sonraki atlama IP" :id="$prefix.'-hop-manual'"
                  data-assignment-skip-field="manual" data-assignment-step="skip" hidden
                  hint="Müşteri switchleri için elle girilir.">
        <x-form.input name="next_hop" :id="$prefix.'-hop-manual'" :value="$manualNextHop ? $block->next_hop : null"
                      placeholder="198.18.21.1" mono />
    </x-form.field>
</div>

<div class="form-grid field--wide" data-assignment-field="prefix_list" hidden>
    {{-- Liste omurga switchte durur; switch seçilmeden sorulmaz. --}}
    <x-form.field name="prefix_list_id" label="Prefix list" :id="$prefix.'-prefix-list'"
                  data-assignment-step="backbone" hidden
                  hint="Omurga switchteki listeler; cihazdan okunur.">
        <x-form.select name="prefix_list_id" :id="$prefix.'-prefix-list'" :options="$prefixListOptions"
                       :selected="$block?->prefixList?->uuid" />
    </x-form.field>
</div>
