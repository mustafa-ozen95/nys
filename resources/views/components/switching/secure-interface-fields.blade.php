@props(['rule' => null, 'prefix' => 'snoop-port', 'actionOptions' => [], 'trustOptions' => [], 'vlanScopedFields' => [], 'vlanOptions' => []])

{{--
    Arayüzün port güvenliği kuralları.

    Ekranın en tehlikeli alanı "DHCP güven durumu"dur ve uyarı alanın
    KENDİSİNDE durur: güvenilir işaretlenen porta takılan herhangi bir cihaz
    ağa DHCP cevabı dağıtabilir ve snooping’in bütün koruması ortadan kalkar.
    Aynı uyarı onay penceresinde tekrarlanır.
--}}
<x-ui.alert variant="warning">
    <strong>“Güvenilir” yalnızca uplink portuna verilir.</strong>
    Güvenilir port DHCP sunucusu cevabı geçirebilir. Bir kullanıcı portu güvenilir işaretlenirse
    o porta takılan herhangi bir cihaz ağın DHCP sunucusu gibi davranabilir — snooping’in koruması
    tamamen ortadan kalkar.
</x-ui.alert>

@if ($vlanScopedFields)
    <x-form.field rule="switch_secure_access.arayuz:vlan_name" name="vlan_name" label="VLAN" :id="$prefix.'-vlan'"
                  hint="Bu cihazda DHCP güveni ve izinli MAC listesi VLAN’a bağlıdır; hangi VLAN olduğu sorulur.">
        <x-form.select name="vlan_name" :id="$prefix.'-vlan'" :options="$vlanOptions"
                       :selected="$rule?->vlan_name" />
    </x-form.field>
@endif

<x-form.field rule="switch_secure_access.arayuz:dhcp_trusted" name="dhcp_trusted" label="DHCP güven durumu" :id="$prefix.'-trust'"
              hint="Cihazın varsayılanı: trunk portlar güvenilir, erişim portları güvenilmezdir. Buradan yazmak o varsayılanı AÇIKÇA ezer.">
    <x-form.select name="dhcp_trusted" :id="$prefix.'-trust'" :options="$trustOptions"
                   :selected="$rule?->trust_value ?? ''" />
</x-form.field>

<x-form.field rule="switch_secure_access.arayuz:mac_limit" name="mac_limit" label="MAC sınırı" :id="$prefix.'-limit'"
              hint="Portun öğrenebileceği en fazla MAC adresi sayısı. Altına takılan bir switch bu sınırı hemen aşar.">
    <x-form.input type="number" name="mac_limit" :id="$prefix.'-limit'"
                  :value="$rule?->mac_limit" min="1" />
</x-form.field>

<x-form.field rule="switch_secure_access.arayuz:mac_limit_action" name="mac_limit_action" label="Sınır aşılınca" :id="$prefix.'-action'"
              hint="“Portu kapat” seçildiğinde sınırı aşan port HİZMET DIŞI kalır ve kendiliğinden geri gelmez; sahada birinin gitmesi gerekir.">
    <x-form.select name="mac_limit_action" :id="$prefix.'-action'" :options="$actionOptions"
                   :selected="$rule?->mac_limit_action?->value" />
</x-form.field>

<x-form.field rule="switch_secure_access.arayuz:allowed_macs" name="allowed_macs" label="İzin verilen MAC adresleri" :id="$prefix.'-mac'"
              class="field--wide"
              hint="Her satıra bir adres: 00:11:22:33:44:55. Liste doluyken porta yalnızca bu adresler bağlanabilir; boş bırakılırsa kısıt yoktur.">
    {{-- Bileşen diziyi kendisi satırlara açar; Blade'de birleştirme yapılmaz. --}}
    <x-form.textarea name="allowed_macs" :id="$prefix.'-mac'" rows="4" :value="$rule?->allowed_macs" />
</x-form.field>

@if ($rule?->preserved_lines)
    <x-ui.alert variant="info">
        Bu kuralda panelin göstermediği {{ count($rule->preserved_lines) }} satır var
        ({{ implode(', ', $rule->preserved_lines) }}). Kaydettiğinizde cihazda olduğu gibi korunurlar.
        Panel bu satırları yönetmez ama silmez de.
    </x-ui.alert>
@endif
