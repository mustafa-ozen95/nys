@props(['interface' => null, 'interfaceOptions' => [], 'prefix' => 'yeni'])

{{--
    Arayüz adı cihazda KİMLİKTİR; düzenlemede değiştirilemez. "all" Junos'un
    kabul ettiği sanal addır ve bütün portlara uygulanır.
--}}
@if ($interface === null)
    <x-form.field rule="switch_rstp.arayuz:interface_name" name="interface_name" label="Arayüz" :id="$prefix.'-interface'"
                  hint="“Bütün portlar” seçilirse ayar port başına yazılmaz, tek satırla hepsine uygulanır.">
        <x-form.select name="interface_name" :id="$prefix.'-interface'" :options="$interfaceOptions" />
    </x-form.field>
@else
    <x-form.field rule="switch_rstp.arayuz:interface_name" name="interface_name" label="Arayüz" :id="$prefix.'-interface'"
                  hint="Cihazda kimliktir; değişecekse kayıt silinip yeniden eklenir.">
        <x-form.input name="interface_name" :id="$prefix.'-interface'"
                      :value="$interface->interface_name" mono readonly />
    </x-form.field>
@endif

<x-form.field rule="switch_rstp.arayuz:edge" name="edge" label="Uç nokta (edge)" :id="$prefix.'-edge'"
              hint="Sunucu ya da istemci bağlı port. Dinleme/öğrenme beklemesini kaldırır. SWITCH bağlı porta verilirse döngü oluşur.">
    <label class="checkbox">
        <input type="hidden" name="edge" value="0">
        <input type="checkbox" name="edge" id="{{ $prefix }}-edge" value="1" @checked($interface?->edge)>
        <span>Bu port bir uç noktadır</span>
    </label>
</x-form.field>

<x-form.field rule="switch_rstp.arayuz:no_root_port" name="no_root_port" label="Kök port olmasın" :id="$prefix.'-noroot'"
              hint="Bu porttan gelen üstün BPDU dikkate alınmaz; kök köprüye bu yönden gidilmez.">
    <label class="checkbox">
        <input type="hidden" name="no_root_port" value="0">
        <input type="checkbox" name="no_root_port" id="{{ $prefix }}-noroot" value="1"
               @checked($interface?->no_root_port)>
        <span>Kök port olarak seçilmesin</span>
    </label>
</x-form.field>

<x-form.field rule="switch_rstp.arayuz:cost" name="cost" label="Maliyet (isteğe bağlı)" :id="$prefix.'-cost'"
              hint="Düşük maliyet bu yolu tercih ettirir. Boş bırakılırsa cihaz hızdan hesaplar.">
    <x-form.input name="cost" :id="$prefix.'-cost'" type="number" :value="$interface?->cost"
                  min="1" max="200000000" mono />
</x-form.field>

<x-form.field rule="switch_rstp.arayuz:priority" name="priority" label="Port önceliği (isteğe bağlı)" :id="$prefix.'-priority'"
              hint="16’nın katı olmalı (0–240). Boş bırakılırsa cihazın varsayılanı (128) geçerlidir.">
    <x-form.input name="priority" :id="$prefix.'-priority'" type="number" :value="$interface?->priority"
                  min="0" max="240" step="16" mono />
</x-form.field>
