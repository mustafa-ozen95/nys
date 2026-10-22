{{--
    Firewall teriminin bütün alanları.

    Junos'un filtre dili geniştir ve panel bu yüzeyin PRATİK tamamını
    modeller; modellemediği satırlar da ham hâlleriyle korunur (aşağıdaki
    "korunan satır" uyarısı). Anlamadığı bir satır yüzünden filtreyi salt
    okunur ilan etmek, kullanıcının onu hiç yönetememesi demek olurdu.

    Ailesi uyuşmayan koşullar GİZLENİR ve aynı kısıt sunucuda da vardır
    (FirewallTermRequest::guardFamily); alanı gizlemek tek başına koruma
    değildir.
--}}
@props([
    'term' => null,
    'family' => \App\Enums\FirewallFamily::Inet,
    'positionOptions' => [],
    'prefix' => 'yeni',
    'prefixListOptions' => [],
    'protocolOptions' => [],
    'nextHeaderOptions' => [],
    'icmpTypeOptions' => [],
    'ipOptionOptions' => [],
    'fragmentFlagOptions' => [],
    'precedenceOptions' => [],
    'etherTypeOptions' => [],
    'dscpMatchOptions' => [],
    'actionOptions' => [],
    'policerOptions' => [],
    'lossPriorityOptions' => [],
    'forwardingClassOptions' => [],
    'dscpOptions' => [],
])

<x-form.field name="name" label="Terim adı" :id="$prefix.'-term'"
              hint="Filtre içinde terimin kimliğidir.">
    <x-form.input name="name" :id="$prefix.'-term'" :value="$term?->name" mono required placeholder="kabul" />
</x-form.field>

{{--
    Sıra trafiği doğrudan belirler: Junos'ta İLK EŞLEŞEN terim kazanır ve
    kendinden sonrakiler hiç çalışmaz. Bir "her şeyi düşür" teriminin önüne
    geçmek için sıra buradan değiştirilir.
--}}
@if ($positionOptions !== [])
    <x-form.field rule="switch_firewall.terim:position" name="position" label="Sıra" :id="$prefix.'-order'"
                  hint="Bu terim kaçıncı sırada değerlendirilsin? İlk eşleşen kazanır.">
        <x-form.select name="position" :id="$prefix.'-order'" :options="$positionOptions"
                       :selected="$term?->position" />
    </x-form.field>
@endif

<p class="eyebrow">Koşullar (from)</p>

<x-form.field rule="switch_firewall.terim:source_addresses" name="source_addresses" label="Kaynak adresler" :id="$prefix.'-source'"
              hint="Her satıra bir CIDR. Tek adres için /32 yazın. Boşsa kaynağa bakılmaz.">
    <x-form.textarea name="source_addresses" :id="$prefix.'-source'" rows="2"
                     :value="$term?->source_addresses ?? []" />
</x-form.field>

<x-form.field rule="switch_firewall.terim:destination_addresses" name="destination_addresses" label="Hedef adresler" :id="$prefix.'-target'"
              hint="Her satıra bir CIDR.">
    <x-form.textarea name="destination_addresses" :id="$prefix.'-target'" rows="2"
                     :value="$term?->destination_addresses ?? []" />
</x-form.field>

@if ($prefixListOptions !== [])
    {{--
        "Hariç tut" (except) koşulu TERSİNE çevirir: liste dışındakiler
        eşleşir. Okunmadığı ve yazılmadığı sürece filtre sessizce ters
        çalışırdı.
    --}}
    <x-form.field rule="switch_firewall.terim:source_prefix_lists" name="source_prefix_lists" label="Kaynak prefix listeler"
                  hint="Yalnızca cihazda tanımlı listeler seçilebilir. “Hariç tut” koşulu tersine çevirir.">
        @foreach ($prefixListOptions as $list)
            <div class="row row--tight">
                <label class="checkbox">
                    <input type="checkbox" name="source_prefix_lists[]" value="{{ $list }}"
                           @checked(in_array($list, $term?->source_prefix_lists ?? [], true))>
                    <span class="mono">{{ $list }}</span>
                </label>
                <label class="checkbox">
                    <input type="checkbox" name="source_prefix_list_excepts[]" value="{{ $list }}"
                           @checked(in_array($list, $term?->source_prefix_list_excepts ?? [], true))>
                    <span>hariç tut</span>
                </label>
            </div>
        @endforeach
    </x-form.field>

    <x-form.field rule="switch_firewall.terim:destination_prefix_lists" name="destination_prefix_lists" label="Hedef prefix listeler"
                  hint="“Hariç tut” koşulu tersine çevirir.">
        @foreach ($prefixListOptions as $list)
            <div class="row row--tight">
                <label class="checkbox">
                    <input type="checkbox" name="destination_prefix_lists[]" value="{{ $list }}"
                           @checked(in_array($list, $term?->destination_prefix_lists ?? [], true))>
                    <span class="mono">{{ $list }}</span>
                </label>
                <label class="checkbox">
                    <input type="checkbox" name="destination_prefix_list_excepts[]" value="{{ $list }}"
                           @checked(in_array($list, $term?->destination_prefix_list_excepts ?? [], true))>
                    <span>hariç tut</span>
                </label>
            </div>
        @endforeach
    </x-form.field>
@endif

<x-form.field rule="switch_firewall.terim:protocols" name="protocols" label="Protokoller">
    <div class="choices">
        @foreach ($protocolOptions as $protocol)
            <label class="checkbox">
                <input type="checkbox" name="protocols[]" value="{{ $protocol }}"
                       @checked(in_array($protocol, $term?->protocols ?? [], true))>
                <span class="mono">{{ $protocol }}</span>
            </label>
        @endforeach
    </div>
</x-form.field>

{{-- IPv6'da protokolün karşılığı "next-header"dır. --}}
<x-firewall.family-block :family="$family" only="inet6">
    <x-form.field rule="switch_firewall.terim:next_headers" name="next_headers" label="Sonraki başlık (next-header)"
                  hint="IPv6 filtresinde protokolün karşılığıdır.">
        <div class="choices">
            @foreach ($nextHeaderOptions as $header)
                <label class="checkbox">
                    <input type="checkbox" name="next_headers[]" value="{{ $header }}"
                           @checked(in_array($header, $term?->next_headers ?? [], true))>
                    <span class="mono">{{ $header }}</span>
                </label>
            @endforeach
        </div>
    </x-form.field>

    <x-form.field rule="switch_firewall.terim:traffic_classes" name="traffic_classes" label="Trafik sınıfı" :id="$prefix.'-tc'"
                  hint="IPv6'da DSCP'nin karşılığı. Her satıra bir değer.">
        <x-form.textarea name="traffic_classes" :id="$prefix.'-tc'" rows="2"
                         :value="$term?->traffic_classes ?? []" />
    </x-form.field>
</x-firewall.family-block>

<x-form.field rule="switch_firewall.terim:ports" name="ports" label="Portlar" :id="$prefix.'-port'"
              hint="Her satıra bir port numarası. Junos protokol seçilmeden de kabul eder ama genelde tcp/udp ile birlikte kullanılır.">
    <x-form.textarea name="ports" :id="$prefix.'-port'" rows="2" :value="$term?->ports ?? []" />
</x-form.field>

<x-form.field rule="switch_firewall.terim:source_ports" name="source_ports" label="Kaynak portlar" :id="$prefix.'-src-port'">
    <x-form.textarea name="source_ports" :id="$prefix.'-src-port'" rows="2" :value="$term?->source_ports ?? []" />
</x-form.field>

<x-form.field rule="switch_firewall.terim:destination_ports" name="destination_ports" label="Hedef portlar" :id="$prefix.'-dst-port'">
    <x-form.textarea name="destination_ports" :id="$prefix.'-dst-port'" rows="2"
                     :value="$term?->destination_ports ?? []" />
</x-form.field>

{{--
    TCP bayrakları ifade olarak yazılır: "syn", "!ack", "(syn & !ack)".
    Kısayollar (ilk paket / kurulu bağlantı) ifadeyle BİRLİKTE kullanılamaz;
    cihaz reddeder, sunucu da reddeder.
--}}
<x-form.field rule="switch_firewall.terim:tcp_flags" name="tcp_flags" label="TCP bayrakları" :id="$prefix.'-tcp'"
              hint="Örnek: syn, !ack, (syn &amp; !ack). Aşağıdaki kısayollarla birlikte kullanılamaz.">
    <x-form.input name="tcp_flags" :id="$prefix.'-tcp'" :value="$term?->tcp_flags" mono placeholder="syn" />
</x-form.field>

<x-form.field rule="switch_firewall.terim:is_tcp_initial" name="is_tcp_initial" label="TCP kısayolları" :id="$prefix.'-tcpi'">
    <label class="checkbox">
        <input type="hidden" name="is_tcp_initial" value="0">
        <input type="checkbox" name="is_tcp_initial" id="{{ $prefix }}-tcpi" value="1"
               @checked($term?->is_tcp_initial)>
        <span>Yalnızca ilk paket (tcp-initial)</span>
    </label>
    <label class="checkbox">
        <input type="hidden" name="is_tcp_established" value="0">
        <input type="checkbox" name="is_tcp_established" value="1" @checked($term?->is_tcp_established)>
        <span>Kurulu bağlantı (tcp-established)</span>
    </label>
</x-form.field>

<x-form.field rule="switch_firewall.terim:icmp_types" name="icmp_types" label="ICMP türleri"
              hint="Yalnızca protokol icmp seçildiğinde anlamlıdır.">
    <div class="choices">
        @foreach ($icmpTypeOptions as $type)
            <label class="checkbox">
                <input type="checkbox" name="icmp_types[]" value="{{ $type }}"
                       @checked(in_array($type, $term?->icmp_types ?? [], true))>
                <span class="mono">{{ $type }}</span>
            </label>
        @endforeach
    </div>
</x-form.field>

<x-form.field rule="switch_firewall.terim:icmp_codes" name="icmp_codes" label="ICMP kodları" :id="$prefix.'-icmp-code'"
              hint="Her satıra bir kod adı ya da numarası.">
    <x-form.textarea name="icmp_codes" :id="$prefix.'-icmp-code'" rows="2" :value="$term?->icmp_codes ?? []" />
</x-form.field>

<x-form.field rule="switch_firewall.terim:dscps" name="dscps" label="DSCP eşleşmesi">
    <div class="choices">
        @foreach ($dscpMatchOptions as $dscp)
            <label class="checkbox">
                <input type="checkbox" name="dscps[]" value="{{ $dscp }}"
                       @checked(in_array($dscp, $term?->dscps ?? [], true))>
                <span class="mono">{{ $dscp }}</span>
            </label>
        @endforeach
    </div>
</x-form.field>

<x-form.field rule="switch_firewall.terim:packet_lengths" name="packet_lengths" label="Paket boyu" :id="$prefix.'-length'"
              hint="Her satıra bir değer ya da aralık: 1400 veya 576-1500.">
    <x-form.textarea name="packet_lengths" :id="$prefix.'-length'" rows="2"
                     :value="$term?->packet_lengths ?? []" />
</x-form.field>

<x-form.field rule="switch_firewall.terim:ttls" name="ttls" label="TTL" :id="$prefix.'-ttl'"
              hint="Her satıra bir değer ya da aralık: 64 veya 10-20. Sahte kaynaklı trafiği paket boyuyla birlikte ayıklamakta kullanılır.">
    <x-form.textarea name="ttls" :id="$prefix.'-ttl'" rows="2" :value="$term?->ttls ?? []" />
</x-form.field>

<x-form.field rule="switch_firewall.terim:interfaces" name="interfaces" label="Arayüzler" :id="$prefix.'-interface'"
              hint="Her satıra bir arayüz: ge-0/0/5.0. Filtre birden çok yere bağlıysa terimi tek arayüze daraltır.">
    <x-form.textarea name="interfaces" :id="$prefix.'-interface'" rows="2" :value="$term?->interfaces ?? []" />
</x-form.field>

{{-- Yalnızca IPv4 başlığında bulunan koşullar. --}}
<x-firewall.family-block :family="$family" only="inet">
    <x-form.field rule="switch_firewall.terim:precedences" name="precedences" label="IP önceliği (precedence)">
        <div class="choices">
            @foreach ($precedenceOptions as $precedence)
                <label class="checkbox">
                    <input type="checkbox" name="precedences[]" value="{{ $precedence }}"
                           @checked(in_array($precedence, $term?->precedences ?? [], true))>
                    <span class="mono">{{ $precedence }}</span>
                </label>
            @endforeach
        </div>
    </x-form.field>

    <x-form.field rule="switch_firewall.terim:ip_options" name="ip_options" label="IP seçenekleri">
        <div class="choices">
            @foreach ($ipOptionOptions as $option)
                <label class="checkbox">
                    <input type="checkbox" name="ip_options[]" value="{{ $option }}"
                           @checked(in_array($option, $term?->ip_options ?? [], true))>
                    <span class="mono">{{ $option }}</span>
                </label>
            @endforeach
        </div>
    </x-form.field>

    <x-form.field rule="switch_firewall.terim:fragment_flags" name="fragment_flags" label="Parça bayrakları">
        <div class="choices">
            @foreach ($fragmentFlagOptions as $flag)
                <label class="checkbox">
                    <input type="checkbox" name="fragment_flags[]" value="{{ $flag }}"
                           @checked(in_array($flag, $term?->fragment_flags ?? [], true))>
                    <span class="mono">{{ $flag }}</span>
                </label>
            @endforeach
        </div>
    </x-form.field>

    <x-form.field rule="switch_firewall.terim:is_fragment" name="is_fragment" label="Parça koşulu" :id="$prefix.'-part'"
                  hint="İkisi birlikte seçilemez.">
        <label class="checkbox">
            <input type="hidden" name="is_fragment" value="0">
            <input type="checkbox" name="is_fragment" id="{{ $prefix }}-part" value="1"
                   @checked($term?->is_fragment)>
            <span>Parçalanmış paket (is-fragment)</span>
        </label>
        <label class="checkbox">
            <input type="hidden" name="is_first_fragment" value="0">
            <input type="checkbox" name="is_first_fragment" value="1" @checked($term?->is_first_fragment)>
            <span>İlk parça (first-fragment)</span>
        </label>
    </x-form.field>
</x-firewall.family-block>

{{-- Yalnızca ethernet-switching filtresinde bulunan koşullar. --}}
<x-firewall.family-block :family="$family" only="ethernet-switching">
    <x-form.field rule="switch_firewall.terim:ether_types" name="ether_types" label="Ether türü">
        <div class="choices">
            @foreach ($etherTypeOptions as $type)
                <label class="checkbox">
                    <input type="checkbox" name="ether_types[]" value="{{ $type }}"
                           @checked(in_array($type, $term?->ether_types ?? [], true))>
                    <span class="mono">{{ $type }}</span>
                </label>
            @endforeach
        </div>
    </x-form.field>

    <x-form.field rule="switch_firewall.terim:source_mac_addresses" name="source_mac_addresses" label="Kaynak MAC adresleri" :id="$prefix.'-src-mac'"
                  hint="Her satıra bir MAC. Uzunluk verilebilir: 00:11:22:00:00:00/24.">
        <x-form.textarea name="source_mac_addresses" :id="$prefix.'-src-mac'" rows="2"
                         :value="$term?->source_mac_addresses ?? []" />
    </x-form.field>

    <x-form.field rule="switch_firewall.terim:destination_mac_addresses" name="destination_mac_addresses" label="Hedef MAC adresleri" :id="$prefix.'-dst-mac'">
        <x-form.textarea name="destination_mac_addresses" :id="$prefix.'-dst-mac'" rows="2"
                         :value="$term?->destination_mac_addresses ?? []" />
    </x-form.field>
</x-firewall.family-block>

<p class="eyebrow">Eylemler (then)</p>

<x-form.field rule="switch_firewall.terim:action" name="action" label="Sonuç" :id="$prefix.'-result'"
              hint="Boş bırakılırsa Junos paketi kabul eder (Juniper belgesi). Bu sürümde “reject &lt;kod&gt;” YOKTUR; yalnızca sade reject vardır.">
    <x-form.select name="action" :id="$prefix.'-result'" :options="$actionOptions"
                   :selected="$term?->action?->value ?? 'accept'" />
</x-form.field>

{{--
    "Sonraki terime geç" zinciri BİTİRMEZ; sonuçla birlikte seçilemez ve
    sunucu da reddeder.
--}}
<x-form.field rule="switch_firewall.terim:goes_to_next_term" name="goes_to_next_term" label="Sonraki terim" :id="$prefix.'-nextterm'"
              hint="Seçilirse terim zinciri bitirmez, sıradaki terime geçer. Sonuçla birlikte kullanılamaz.">
    <label class="checkbox">
        <input type="hidden" name="goes_to_next_term" value="0">
        <input type="checkbox" name="goes_to_next_term" id="{{ $prefix }}-nextterm" value="1"
               @checked($term?->goes_to_next_term)>
        <span>Sonraki terime geç (next term)</span>
    </label>
</x-form.field>

@if (count($policerOptions) > 1)
    <x-form.field rule="switch_firewall.terim:policer" name="policer" label="Policer" :id="$prefix.'-policer'"
                  hint="Eşleşen trafiğe hız sınırı uygular. Yalnızca cihazda tanımlı policer seçilebilir.">
        <x-form.select name="policer" :id="$prefix.'-policer'" :options="$policerOptions"
                       :selected="$term?->policer" />
    </x-form.field>
@endif

<x-form.field rule="switch_firewall.terim:forwarding_class" name="forwarding_class" label="İletim sınıfı" :id="$prefix.'-class'">
    <x-form.select name="forwarding_class" :id="$prefix.'-class'" :options="$forwardingClassOptions"
                   :selected="$term?->forwarding_class" />
</x-form.field>

<x-form.field rule="switch_firewall.terim:loss_priority" name="loss_priority" label="Kayıp önceliği" :id="$prefix.'-loss'"
              hint="Tıkanıklıkta önce hangi paketin düşürüleceğini belirler.">
    <x-form.select name="loss_priority" :id="$prefix.'-loss'" :options="$lossPriorityOptions"
                   :selected="$term?->loss_priority" />
</x-form.field>

<x-form.field rule="switch_firewall.terim:dscp" name="dscp" label="DSCP damgası" :id="$prefix.'-dscp'"
              hint="Eşleşen paketin DSCP değerini yeniden yazar.">
    <x-form.select name="dscp" :id="$prefix.'-dscp'" :options="$dscpOptions" :selected="$term?->dscp" />
</x-form.field>

<x-form.field rule="switch_firewall.terim:routing_instance" name="routing_instance" label="Yönlendirme tablosu" :id="$prefix.'-table'"
              hint="Eşleşen trafiği başka bir routing-instance'a yollar.">
    <x-form.input name="routing_instance" :id="$prefix.'-table'" :value="$term?->routing_instance" mono />
</x-form.field>

<x-form.field rule="switch_firewall.terim:analyzer" name="analyzer" label="Analyzer" :id="$prefix.'-analyzer'"
              hint="Eşleşen trafiği tanımlı bir analyzer oturumuna kopyalar.">
    <x-form.input name="analyzer" :id="$prefix.'-analyzer'" :value="$term?->analyzer" mono />
</x-form.field>

<x-form.field rule="switch_firewall.terim:count_name" name="count_name" label="Sayaç adı" :id="$prefix.'-counter'"
              hint="Verilirse eşleşen paketler sayılır; sorun ararken işe yarar.">
    <x-form.input name="count_name" :id="$prefix.'-counter'" :value="$term?->count_name" mono />
</x-form.field>

<x-form.field rule="switch_firewall.terim:is_logged" name="is_logged" label="Kayıt ve kopyalama" :id="$prefix.'-log'">
    <label class="checkbox">
        <input type="hidden" name="is_logged" value="0">
        <input type="checkbox" name="is_logged" id="{{ $prefix }}-log" value="1" @checked($term?->is_logged)>
        <span>Eşleşen paketleri günlüğe yaz (log)</span>
    </label>
    <label class="checkbox">
        <input type="hidden" name="is_syslogged" value="0">
        <input type="checkbox" name="is_syslogged" value="1" @checked($term?->is_syslogged)>
        <span>Sistem günlüğüne yaz (syslog)</span>
    </label>
    <label class="checkbox">
        <input type="hidden" name="is_port_mirrored" value="0">
        <input type="checkbox" name="is_port_mirrored" value="1" @checked($term?->is_port_mirrored)>
        <span>Port aynala (port-mirror)</span>
    </label>
    <label class="checkbox">
        <input type="hidden" name="is_sampled" value="0">
        <input type="checkbox" name="is_sampled" value="1" @checked($term?->is_sampled)>
        <span>Örnekle (sample)</span>
    </label>
</x-form.field>

{{--
    Panelin göstermediği ama KORUNAN satırlar. Kullanıcı neyin taşındığını
    bilmeli: kaydettiğinde bunlar cihazda olduğu gibi kalır.
--}}
@if ($term?->preserved_lines)
    <x-ui.alert variant="info">
        Bu terimde panelin göstermediği {{ count($term->preserved_lines) }} satır var
        ({{ implode(', ', $term->preserved_lines) }}). Kaydettiğinizde cihazda olduğu gibi korunurlar.
    </x-ui.alert>
@endif
