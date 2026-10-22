@props(['rule' => null, 'prefix' => 'snoop-vlan', 'actionOptions' => []])

{{--
    VLAN'ın erişim güvenliği kuralları.

    Sıra anlamlıdır: DHCP snooping en üstte durur çünkü diğer iki koruma
    ONUN kurduğu bağlama tablosuna dayanır. Snooping kapalıyken tablo boş
    kalır ve IP kaynak koruması o VLAN'daki bütün trafiği düşürür — sunucu
    da bu birleşimi reddeder.
--}}
<x-form.field rule="switch_secure_access.vlan:examine_dhcp" name="examine_dhcp" label="DHCP snooping" :id="$prefix.'-snooping'"
              hint="Cihaz VLAN’daki DHCP trafiğini inceler ve “hangi MAC hangi IP’yi hangi porttan aldı” tablosunu kurar. Diğer iki koruma bu tabloya dayanır.">
    <label class="checkbox">
        <input type="hidden" name="examine_dhcp" value="0">
        <input type="checkbox" name="examine_dhcp" id="{{ $prefix }}-snooping"
               value="1" @checked($rule?->examine_dhcp)>
        <span>Açık (examine-dhcp)</span>
    </label>
</x-form.field>

<x-form.field rule="switch_secure_access.vlan:arp_inspection" name="arp_inspection" label="ARP denetimi" :id="$prefix.'-arp'"
              hint="Bağlama tablosuyla uyuşmayan ARP cevapları düşürülür; ortadaki adam saldırısını engeller. DHCP snooping olmadan çalışmaz.">
    <label class="checkbox">
        <input type="hidden" name="arp_inspection" value="0">
        <input type="checkbox" name="arp_inspection" id="{{ $prefix }}-arp"
               value="1" @checked($rule?->arp_inspection)>
        <span>Açık (arp-inspection)</span>
    </label>
</x-form.field>

<x-form.field rule="switch_secure_access.vlan:ip_source_guard" name="ip_source_guard" label="IP kaynak koruması" :id="$prefix.'-guard'"
              hint="Kaynak IP’si bağlama tablosunda olmayan paketler düşürülür. STATİK adresli cihazlar (yazıcı, sunucu, kamera) tabloda yoktur ve trafikleri kesilir.">
    <label class="checkbox">
        <input type="hidden" name="ip_source_guard" value="0">
        <input type="checkbox" name="ip_source_guard" id="{{ $prefix }}-guard"
               value="1" @checked($rule?->ip_source_guard)>
        <span>Açık (ip-source-guard)</span>
    </label>
</x-form.field>

<x-form.field rule="switch_secure_access.vlan:mac_move_limit" name="mac_move_limit" label="MAC taşıma sınırı" :id="$prefix.'-limit'"
              hint="Bir MAC adresinin saniyeler içinde kaç kez port değiştirebileceği. Döngü ve MAC taşması bu sınırla yakalanır; boş bırakılırsa cihazın varsayılanı geçerlidir.">
    <x-form.input type="number" name="mac_move_limit" :id="$prefix.'-limit'"
                  :value="$rule?->mac_move_limit" min="1" />
</x-form.field>

<x-form.field rule="switch_secure_access.vlan:mac_move_action" name="mac_move_action" label="Sınır aşılınca" :id="$prefix.'-action'"
              hint="“Portu kapat” seçildiğinde sınırı aşan port HİZMET DIŞI kalır ve kendiliğinden geri gelmez.">
    <x-form.select name="mac_move_action" :id="$prefix.'-action'" :options="$actionOptions"
                   :selected="$rule?->mac_move_action?->value" />
</x-form.field>

@if ($rule?->preserved_lines)
    <x-ui.alert variant="info">
        Bu kuralda panelin göstermediği {{ count($rule->preserved_lines) }} satır var
        ({{ implode(', ', $rule->preserved_lines) }}). Kaydettiğinizde cihazda olduğu gibi korunurlar.
    </x-ui.alert>
@endif
