{{-- Panelden yalnızca statik route yazılır; protokol seçilmez. Direct, Local,
     OSPF ve BGP yolları cihaz tarafından öğrenilir, yapılandırmada karşılıkları
     yoktur. Statik route'un GERİ KALAN HER ALANI buradan yönetilir. --}}
@props(['route' => null, 'prefix' => 'route', 'networkOptions' => [], 'nextHopOptions' => []])

{{--
    Yolun ne yaptığı ve düzenlenebilir atlamaları MODELDEN gelir
    (SwitchRoute::action, ::editableNextHops), burada hesaplanmaz: aynı
    sözlüğün öbür ucu yazma tarafında duruyor ve ikisi ayrı düşerse form
    cihazdakinden başka bir şey gösterirdi.
--}}

{{--
    Hedef ağ tek alandır ("adres/maske"): ayrı sorulursa ikisi uyumsuz
    bırakılabilir. IPv4 ve IPv6 ikisi de kabul edilir; IPv6 hedefte route
    cihazda "rib inet6.0" altına yazılır ve bu kullanıcıya sorulmaz.
--}}
<x-form.field rule="switch_routes.route:cidr" name="cidr" label="Hedef ağ" :id="$prefix.'-target'"
              hint="Adresleme’de tanımlı bloklar listelenir. Varsayılan route için 0.0.0.0/0, IPv6 için 2001:db8::/32 biçimi.">
    <x-form.ip-picker name="cidr" :id="$prefix.'-target'" :options="$networkOptions"
                      :value="$route?->cidr" manual placeholder="0.0.0.0/0"
                      empty-text="Adresleme’de blok yok; hedef ağı elle yazın." />
</x-form.field>

<x-form.field rule="switch_routes.route:action" name="action" label="Yol ne yapsın?" :id="$prefix.'-action'"
              hint="Discard sessizce düşürür; reject ICMP unreachable döndürür; receive yerel olarak alır.">
    <x-form.select name="action" :id="$prefix.'-action'" :selected="($route?->action ?? 'next-hop')" data-route-action
                   :options="[
                       'next-hop' => 'Sonraki atlamaya yönlendir',
                       'discard' => 'Düşür (discard)',
                       'reject' => 'Reddet (reject)',
                       'receive' => 'Yerel al (receive)',
                   ]" />
</x-form.field>

{{--
    BİRDEN ÇOK ATLAMA = ECMP. Cihaz destekliyor; yalnızca ilkini yazan bir
    form yedekli çıkışı olan route'u düzenletemezdi.
--}}
<div data-route-hop class="{{ ($route?->action ?? 'next-hop') === 'next-hop' ? '' : 'is-hidden' }}">
    <x-form.field rule="switch_routes.route:next_hops" name="next_hops" label="Sonraki atlamalar" :id="$prefix.'-hop'"
                  hint="Birden çok adres girilirse trafik aralarında paylaştırılır (ECMP).">
        {{--
            Adres Adresleme'deki uplink ve peering bloklarından seçilir; elle
            giriş açıktır çünkü sağlayıcının ağ geçidi havuzumuzda yer almaz.
        --}}
        <div class="stack stack--tight" data-repeat="next_hops">
            @foreach (($route?->editable_next_hops ?? []) ?: [null] as $i => $hop)
                <div class="row row--tight" data-repeat-row>
                    <x-form.ip-picker name="next_hops[]" :id="$prefix.'-hop-'.$i" :options="$nextHopOptions"
                                      :value="$hop" manual placeholder="198.18.8.37"
                                      empty-text="Adresleme’de uplink veya peering bloğu yok; adresi elle yazın." />
                    <button type="button" class="btn btn--ghost btn--sm" data-repeat-remove>Çıkar</button>
                </div>
            @endforeach

            <button type="button" class="btn btn--sm" data-repeat-add>Atlama ekle</button>
        </div>
    </x-form.field>

    {{--
        Yedekli çıkışın standart biçimi: birincil atlama + kendi
        preference'ı olan qualified-next-hop (floating static route).
    --}}
    <x-form.field rule="switch_routes.route:qualified_next_hops" name="qualified_next_hops" label="Yedek atlamalar" :id="$prefix.'-backup'"
                  hint="Kendi tercih ve metriğiyle çalışan atlama. Yüksek preference = yedek.">
        <div class="stack stack--tight" data-repeat="qualified">
            @foreach (($route?->qualified_next_hops ?: []) as $i => $yedek)
                <div class="row row--tight" data-repeat-row>
                    <x-form.input :name="'qualified_next_hops['.$i.'][name]'" :value="$yedek['name'] ?? ''"
                                  placeholder="198.18.8.38" class="mono" />
                    <x-form.input type="number" :name="'qualified_next_hops['.$i.'][preference]'"
                                  :value="$yedek['preference'] ?? ''" placeholder="preference" />
                    <x-form.input type="number" :name="'qualified_next_hops['.$i.'][metric]'"
                                  :value="$yedek['metric'] ?? ''" placeholder="metric" />
                    <button type="button" class="btn btn--ghost btn--sm" data-repeat-remove>Çıkar</button>
                </div>
            @endforeach

            <button type="button" class="btn btn--sm" data-repeat-add>Yedek atlama ekle</button>
        </div>
    </x-form.field>
</div>

<x-form.field rule="switch_routes.route:preference" name="preference" label="Tercih (preference)" :id="$prefix.'-preference'"
              hint="Boş bırakılırsa cihaz kendi varsayılanını kullanır (statik için 5).">
    <x-form.input type="number" name="preference" :id="$prefix.'-preference'" :value="$route?->preference" />
</x-form.field>

<x-form.field rule="switch_routes.route:metric" name="metric" label="Metrik" :id="$prefix.'-metric'">
    <x-form.input type="number" name="metric" :id="$prefix.'-metric'" :value="$route?->metric" />
</x-form.field>

<x-form.field rule="switch_routes.route:tag" name="tag" label="Etiket (tag)" :id="$prefix.'-tag'"
              hint="Politikaların eşleşmede kullandığı sayısal etiket.">
    <x-form.input type="number" name="tag" :id="$prefix.'-tag'" :value="$route?->tag" />
</x-form.field>

<x-form.field rule="switch_routes.route:tag2" name="tag2" label="İkinci etiket (tag2)" :id="$prefix.'-etiket2'">
    <x-form.input type="number" name="tag2" :id="$prefix.'-etiket2'" :value="$route?->tag2" />
</x-form.field>

{{-- Birbirini dışlayan çiftler sunucuda da engellenir; cihaz zaten reddediyor. --}}
<x-form.field rule="switch_routes.route:flags" name="flags" label="Seçenekler" :id="$prefix.'-options'"
              hint="Cihaz bazı çiftleri birlikte kabul etmiyor (örneğin retain ile resolve).">
    <div class="choices">
        @foreach (\App\Enums\RouteFlag::cases() as $flag)
            <label class="checkbox">
                <input type="checkbox" name="flags[]" value="{{ $flag->value }}"
                       @checked(in_array($flag->value, $route?->flags ?? [], true))>
                <span>{{ $flag->label() }}</span>
            </label>
        @endforeach
    </div>
</x-form.field>

<x-form.field rule="switch_routes.route:communities" name="communities" label="Community" :id="$prefix.'-community'"
              hint="Route'a iliştirilen community değerleri. Örnek: 65000:100">
    <div class="stack stack--tight" data-repeat="communities">
        @foreach ($route?->communities ?? [] as $community)
            <div class="row row--tight" data-repeat-row>
                <x-form.input name="communities[]" :value="$community" class="mono" placeholder="65000:100" />
                <button type="button" class="btn btn--ghost btn--sm" data-repeat-remove>Çıkar</button>
            </div>
        @endforeach

        <button type="button" class="btn btn--sm" data-repeat-add>Community ekle</button>
    </div>
</x-form.field>

{{--
    Panelin göstermediği ama KORUNAN satırlar. Kullanıcı neyin taşındığını
    bilmeli: kaydettiğinde bunlar cihazda olduğu gibi kalır.
--}}
@if ($route?->preserved_lines)
    <x-ui.alert variant="info">
        Bu route’ta panelin göstermediği {{ count($route->preserved_lines) }} satır var
        ({{ implode(', ', $route->preserved_lines) }}). Kaydettiğinizde cihazda olduğu gibi korunurlar.
    </x-ui.alert>
@endif
