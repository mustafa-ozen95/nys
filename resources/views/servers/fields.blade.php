@props(['server' => null])

{{--
    Sunucu alanları.

    TEK yerde durur: aynı alanlar hem "yeni sunucu" sayfasında hem de listedeki
    düzenleme penceresinde çiziliyor. İki kopya olsaydı biri değiştiğinde öteki
    sessizce ayrışırdı.

    Sarmalayan <form> ve altlık ÇAĞIRANA aittir; sayfada geniş bir düzen,
    pencerede modal altlığı gerekiyor.
--}}
<div class="form-grid">
    <x-form.field name="short_name" label="Kısa ad">
        <x-form.input name="short_name" :value="$server?->short_name" mono required autofocus />
    </x-form.field>

    <x-form.field name="long_name" label="Uzun ad">
        <x-form.input name="long_name" :value="$server?->long_name" />
    </x-form.field>

    {{--
        Adres Adresleme'den seçilir; alan adı da kabul edildiği için elle
        giriş her zaman açıktır.
    --}}
    <x-form.field name="ip_address" label="Adres" class="field--wide"
                  hint="Adresleme’deki yönetim ve müşteri blokları listelenir; alan adı için “listede yok” seçin.">
        <x-form.ip-picker name="ip_address" :options="$addressOptions"
                          :value="$server?->ip_address" manual placeholder="198.18.20.20" />
    </x-form.field>

    <x-form.field name="os_type" label="İşletim sistemi">
        <x-form.select name="os_type" :options="$osOptions" :selected="$server?->os_type?->value" />
    </x-form.field>

    <x-form.field name="os_version" label="Sürüm">
        <x-form.input name="os_version" :value="$server?->os_version" placeholder="Ubuntu 24.04 LTS" />
    </x-form.field>

    <x-form.field name="status" label="Durum">
        <x-form.select name="status" :options="$statusOptions" :selected="$server?->status?->value" />
    </x-form.field>
</div>

<details class="fieldgroup">
    <summary class="fieldgroup__head">Erişim bilgileri</summary>

    <div class="fieldgroup__body">
        <div class="form-grid">
            <x-form.field name="connection_protocol" label="Protokol">
                <x-form.select name="connection_protocol" :options="$protocolOptions"
                               :selected="$server?->connection_protocol?->value" />
            </x-form.field>

            <x-form.field name="connection_port" label="Port"
                          hint="Boş bırakılırsa protokolün varsayılanı kullanılır.">
                <x-form.input name="connection_port" type="number" inputmode="numeric"
                              :value="$server?->connection_port" mono />
            </x-form.field>

            <x-form.field name="connection_username" label="Kullanıcı adı">
                <x-form.input name="connection_username" :value="$server?->connection_username" mono required />
            </x-form.field>

            <x-form.field name="connection_password" label="Parola"
                          :hint="$server ? 'Boş bırakılırsa mevcut parola korunur.' : null">
                <x-form.input name="connection_password" type="password" autocomplete="new-password" />
            </x-form.field>
        </div>
    </div>
</details>

<x-form.field name="notes" label="Notlar" class="field--wide">
    <x-form.textarea name="notes" :value="$server?->notes" />
</x-form.field>
