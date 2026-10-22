{{-- Oluşturma ve düzenleme aynı alanları kullanır; ikisi de pencerede açılır. --}}
<x-form.field name="hostname" label="Switch adı" :id="$prefix.'-name'">
    <x-form.input name="hostname" :id="$prefix.'-name'" :value="$switch?->hostname" mono required />
</x-form.field>

{{--
    Adresler Adresleme'den seçilir. Elle giriş bilinçli olarak açıktır: yeni
    cihaz envantere alınırken adresi henüz Adresleme'de tanımlı olmayabilir.
--}}
<x-form.field name="management_ip" label="Yönetim IP" :id="$prefix.'-management'"
              hint="Adresleme’de “Yönetim” amaçlı bloklar listelenir.">
    <x-form.ip-picker name="management_ip" :id="$prefix.'-management'" :options="$managementOptions"
                      :value="$switch?->management_ip" manual placeholder="198.18.20.195" />
</x-form.field>

<x-form.field name="uplink_ip" label="Uplink IP" :id="$prefix.'-uplink'"
              hint="Adresleme’de “Uplink” ve “BGP peering” blokları listelenir.">
    <x-form.ip-picker name="uplink_ip" :id="$prefix.'-uplink'" :options="$uplinkOptions"
                      :value="$switch?->uplink_ip" manual placeholder="198.18.8.38" />
</x-form.field>

<x-form.field name="role" label="Rol" :id="$prefix.'-role'">
    <x-form.select name="role" :id="$prefix.'-role'" :options="$roleOptions" :selected="$switch?->role?->value" />
</x-form.field>

<x-form.field name="location" label="Konum" :id="$prefix.'-location'">
    <x-form.select name="location" :id="$prefix.'-location'" :options="$locationOptions"
                   :selected="$switch?->location?->value" />
</x-form.field>

<x-form.field name="ssh_username" label="SSH kullanıcı adı" :id="$prefix.'-ssh-user'">
    <x-form.input name="ssh_username" :id="$prefix.'-ssh-user'" :value="$sshUsername" mono />
</x-form.field>

<x-form.field name="ssh_password" label="SSH parolası" :id="$prefix.'-ssh-password'"
              :hint="$switch ? 'Boş bırakılırsa mevcut parola korunur.' : null">
    <x-form.input name="ssh_password" :id="$prefix.'-ssh-password'" type="password" autocomplete="new-password" />
</x-form.field>

<x-form.field name="ssh_port" label="SSH portu" :id="$prefix.'-ssh-port'">
    <x-form.input name="ssh_port" :id="$prefix.'-ssh-port'" type="number" inputmode="numeric" :value="$sshPort" mono />
</x-form.field>

<x-form.field name="snmp_community" label="SNMP community" :id="$prefix.'-snmp'"
              :hint="$switch ? 'Boş bırakılırsa mevcut değer korunur.' : null">
    <x-form.input name="snmp_community" :id="$prefix.'-snmp'" type="password" autocomplete="off" />
</x-form.field>

<x-form.field name="snmp_port" label="SNMP portu" :id="$prefix.'-snmp-port'">
    <x-form.input name="snmp_port" :id="$prefix.'-snmp-port'" type="number" inputmode="numeric" :value="$snmpPort" mono />
</x-form.field>

{{-- NETCONF kimliği olmadan cihaz panelden yapılandırılamaz: yazma
     düğmeleri hiç görünmez. --}}
<x-form.field name="netconf_enabled" label="NETCONF" :id="$prefix.'-netconf'"
              hint="Kapalıysa bu switch panelden yapılandırılamaz, yalnızca görüntülenir.">
    <label class="checkbox">
        <input type="hidden" name="netconf_enabled" value="0">
        <input type="checkbox" name="netconf_enabled" id="{{ $prefix }}-netconf" value="1"
               @checked($netconfEnabled) data-netconf-toggle>
        <span>Cihaza yazmaya izin ver</span>
    </label>
</x-form.field>

<div data-netconf-field @unless ($netconfEnabled) hidden @endunless>
    <x-form.field name="netconf_username" label="NETCONF kullanıcı adı" :id="$prefix.'-netconf-user'"
                  hint="Boş bırakılırsa SSH kullanıcısı kullanılır.">
        <x-form.input name="netconf_username" :id="$prefix.'-netconf-user'" :value="$netconfUsername" mono />
    </x-form.field>

    <x-form.field name="netconf_password" label="NETCONF parolası" :id="$prefix.'-netconf-password'"
                  hint="Boş bırakılırsa SSH parolası kullanılır.">
        <x-form.input name="netconf_password" :id="$prefix.'-netconf-password'" type="password" autocomplete="new-password" />
    </x-form.field>

    <x-form.field name="netconf_port" label="NETCONF portu" :id="$prefix.'-netconf-port'">
        <x-form.input name="netconf_port" :id="$prefix.'-netconf-port'" type="number" inputmode="numeric"
                      :value="$netconfPort" mono />
    </x-form.field>
</div>
