<div class="modal__body stack">
    <x-form.field name="name" label="Grup adı" :id="$prefix.'-name'"
                  hint="Cihazda grubun kimliği adıdır ve sonradan değiştirilemez.">
        <x-form.input name="name" :id="$prefix.'-name'" :value="$group?->name"
                      mono required :readonly="$group !== null" />
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:type" name="type" label="Tür" :id="$prefix.'-type'"
                  hint="eBGP farklı bir AS ile, iBGP kendi AS’imiz içinde konuşur.">
        <x-form.select name="type" :id="$prefix.'-type'" :options="$typeOptions"
                       :selected="$group?->type->value ?? 'external'" />
    </x-form.field>

    {{-- peer-as yalnızca external grupta anlamlıdır; iBGP’de karşı taraf zaten biziz. --}}
    <x-form.field rule="switch_bgp.grup:peer_asn" name="peer_asn" label="Karşı taraf AS" :id="$prefix.'-peer'"
                  data-bgp-type="external"
                  hint="Komşunun AS numarası.">
        <x-form.input name="peer_asn" :id="$prefix.'-peer'" :value="$group?->peer_asn"
                      mono placeholder="64497" />
    </x-form.field>

    {{--
        Adres Adresleme'den seçilir. Elle giriş yalnızca havuz dışındaki
        adresler için açıktır.
    --}}
    <x-form.field rule="switch_bgp.grup:local_address" name="local_address" label="Yerel adres" :id="$prefix.'-local'"
                  hint="Oturumun bizim tarafımızdaki kaynak adresi. Adresleme’deki peering ve uplink blokları listelenir.">
        <x-form.ip-picker name="local_address" :id="$prefix.'-local'" :options="$peeringAddressOptions"
                          :value="$group?->local_address" manual placeholder="198.18.8.38"
                          empty-text="Adresleme’de peering veya uplink bloğu yok; adresi elle yazın." />
    </x-form.field>

    {{--
        MD5 oturum kimliği. Sağlayıcı peering'inde yaygındır. Parola gibi
        davranır: değeri ekranda basılmaz, boş bırakılırsa kayıtlı anahtar
        korunur, silmek ayrı kutu ister.
    --}}
    <x-form.field rule="switch_bgp.grup:authentication_key" name="authentication_key" label="Oturum anahtarı (MD5)" :id="$prefix.'-key'"
                  :hint="$group?->authentication_key === null
                      ? 'Karşı taraf MD5 istiyorsa girin. Cihaz anahtarı şifreli saklar.'
                      : 'Kayıtlı anahtar var. Boş bırakılırsa korunur.'">
        <x-form.input type="password" name="authentication_key" :id="$prefix.'-key'"
                      autocomplete="new-password" mono />
    </x-form.field>

    @if ($group?->authentication_key !== null)
        <x-form.field rule="switch_bgp.grup:clear_authentication_key" name="clear_authentication_key" label="Kayıtlı anahtar" :id="$prefix.'-key-delete'"
                      hint="Silinirse oturum kimlik doğrulamasız kurulur.">
            <label class="checkbox">
                <input type="hidden" name="clear_authentication_key" value="0">
                <input type="checkbox" name="clear_authentication_key" id="{{ $prefix }}-key-delete" value="1">
                <span>Anahtarı sil</span>
            </label>
        </x-form.field>
    @endif

    <x-form.field rule="switch_bgp.grup:hold_time" name="hold_time" label="Hold-time (saniye)" :id="$prefix.'-hold'"
                  hint="Boş bırakılırsa cihazın varsayılanı (90 sn) geçerlidir. Karşı tarafla uyuşan küçük değer kullanılır.">
        <x-form.input name="hold_time" :id="$prefix.'-hold'" type="number"
                      :value="$group?->hold_time" min="3" max="65535" />
    </x-form.field>

    {{-- Route reflector: iBGP'de komşuların birbirine tam örgü kurmasını
         gereksiz kılar. Küme kimliği genelde cihazın loopback adresidir. --}}
    <x-form.field rule="switch_bgp.grup:cluster" name="cluster" label="Route reflector kümesi" :id="$prefix.'-cluster'"
                  hint="iBGP grubunda anlamlıdır; boş bırakılırsa bu grup route reflector değildir.">
        <x-form.input name="cluster" :id="$prefix.'-cluster'" :value="$group?->cluster"
                      mono placeholder="10.0.0.1" />
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:local_asn" name="local_asn" label="Yerel AS (isteğe bağlı)" :id="$prefix.'-local-as'"
                  hint="Boş bırakılırsa switchin kendi AS numarası kullanılır.">
        <x-form.input name="local_asn" :id="$prefix.'-local-as'" :value="$group?->local_asn" mono />
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:multihop" name="multihop" label="Multihop" :id="$prefix.'-multihop'"
                  hint="Komşu doğrudan bağlı değilse gerekir.">
        <label class="checkbox">
            <input type="hidden" name="multihop" value="0">
            <input type="checkbox" name="multihop" id="{{ $prefix }}-multihop"
                   value="1" @checked($group?->multihop)>
            <span>Komşu doğrudan bağlı değil</span>
        </label>
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:multihop_ttl" name="multihop_ttl" label="Multihop TTL" :id="$prefix.'-ttl'"
                  data-bgp-approval="multihop"
                  hint="Boş bırakılırsa cihazın varsayılanı kullanılır.">
        <x-form.input name="multihop_ttl" :id="$prefix.'-ttl'" type="number"
                      :value="$group?->multihop_ttl" min="1" max="255" />
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:graceful_restart" name="graceful_restart" label="Graceful restart" :id="$prefix.'-gr'"
                  hint="Cihaz yeniden başlarken yolların korunması. “Cihazın genel ayarı” satır yazmaz; “Kapalı” açıkça kapatır — ikisi aynı şey değildir.">
        <x-form.select name="graceful_restart" :id="$prefix.'-gr'" :options="$gracefulRestartOptions"
                       :selected="$group?->graceful_restart?->value ?? 'default'" />
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:out_delay" name="out_delay" label="Anons gecikmesi (saniye)" :id="$prefix.'-outdelay'"
                  hint="Yol dalgalanıyorsa hemen duyurulmaz. Boş bırakılırsa gecikme yoktur.">
        <x-form.input name="out_delay" :id="$prefix.'-outdelay'" type="number"
                      :value="$group?->out_delay" min="1" max="65535" />
    </x-form.field>

    {{-- BFD komşunun düştüğünü BGP zamanlayıcılarından çok daha hızlı görür. --}}
    <x-form.field rule="switch_bgp.grup:bfd_minimum_interval" name="bfd_minimum_interval" label="BFD aralığı (ms)" :id="$prefix.'-bfd'"
                  hint="Boş bırakılırsa BFD yapılandırılmaz. Karşı taraf da BFD desteklemelidir.">
        <x-form.input name="bfd_minimum_interval" :id="$prefix.'-bfd'" type="number"
                      :value="$group?->bfd_minimum_interval" min="1" max="255000" />
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:multipath" name="multipath" label="Çoklu yol" :id="$prefix.'-multipath'"
                  hint="Aynı hedefe eşit maliyetli birden fazla BGP yolu kullanılsın.">
        <label class="checkbox">
            <input type="hidden" name="multipath" value="0">
            <input type="checkbox" name="multipath" id="{{ $prefix }}-multipath"
                   value="1" @checked($group?->multipath)>
            <span>Eşit maliyetli yolları birlikte kullan</span>
        </label>
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:remove_private" name="remove_private" label="Özel AS’leri temizle" :id="$prefix.'-removepriv'"
                  hint="Anonslardan özel AS numaraları çıkarılır. Transit sağlayıcıya çıkarken beklenen davranıştır.">
        <label class="checkbox">
            <input type="hidden" name="remove_private" value="0">
            <input type="checkbox" name="remove_private" id="{{ $prefix }}-removepriv"
                   value="1" @checked($group?->remove_private)>
            <span>Özel AS numaralarını gönderme</span>
        </label>
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:as_override" name="as_override" label="AS override" :id="$prefix.'-asoverride'"
                  hint="Karşı tarafın AS numarası anonsta kendi numaramızla değiştirilir. Aynı AS’i kullanan iki müşteri şubesi için gerekir.">
        <label class="checkbox">
            <input type="hidden" name="as_override" value="0">
            <input type="checkbox" name="as_override" id="{{ $prefix }}-asoverride"
                   value="1" @checked($group?->as_override)>
            <span>Komşunun AS numarasını değiştir</span>
        </label>
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:is_inactive" name="is_inactive" label="Devre dışı (deactivate)" :id="$prefix.'-inactive'"
                  hint="Yapılandırma cihazda kalır ama ÇALIŞMAZ. Oturumu silmeden geçici olarak kapatmanın yoludur; işaret kaldırılınca aynı ayarlarla geri açılır.">
        <label class="checkbox">
            <input type="hidden" name="is_inactive" value="0">
            <input type="checkbox" name="is_inactive" id="{{ $prefix }}-inactive"
                   value="1" @checked($group?->is_inactive)>
            <span>Bu grup devre dışı olsun</span>
        </label>
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:advertise_inactive" name="advertise_inactive" label="Etkin olmayan yolları anons et" :id="$prefix.'-advinactive'"
                  hint="Cihazın kendi tablosunda etkin olmayan (statik yolun gölgelediği) BGP yolları da duyurulur.">
        <label class="checkbox">
            <input type="hidden" name="advertise_inactive" value="0">
            <input type="checkbox" name="advertise_inactive" id="{{ $prefix }}-advinactive"
                   value="1" @checked($group?->advertise_inactive)>
            <span>Etkin olmayan yolları da duyur</span>
        </label>
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:damping" name="damping" label="Yol bastırma (damping)" :id="$prefix.'-damping'"
                  hint="Sürekli inip kalkan prefixler bir süre bastırılır.">
        <label class="checkbox">
            <input type="hidden" name="damping" value="0">
            <input type="checkbox" name="damping" id="{{ $prefix }}-damping"
                   value="1" @checked($group?->damping)>
            <span>Dalgalanan yolları bastır</span>
        </label>
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:log_updown" name="log_updown" label="Kayıt" :id="$prefix.'-log'"
                  hint="Oturum kalktığında ve düştüğünde cihaz günlüğe yazar.">
        <label class="checkbox">
            <input type="hidden" name="log_updown" value="0">
            <input type="checkbox" name="log_updown" id="{{ $prefix }}-log"
                   value="1" @checked($group?->log_updown ?? true)>
            <span>Durum değişikliklerini günlüğe yaz</span>
        </label>
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:passive" name="passive" label="Pasif" :id="$prefix.'-passive'"
                  hint="Oturumu biz başlatmayız, karşı tarafın bağlanmasını bekleriz.">
        <label class="checkbox">
            <input type="hidden" name="passive" value="0">
            <input type="checkbox" name="passive" id="{{ $prefix }}-passive"
                   value="1" @checked($group?->passive)>
            <span>Bağlantıyı karşı taraf başlatsın</span>
        </label>
    </x-form.field>

    {{--
        Dinamik komşu: adresi tek tek yazmak yerine bir ağdan gelen oturum
        kabul edilir. Müşteri bacaklarında komşu sayısı önceden belli
        olmadığında kullanılır.
    --}}
    <x-form.field rule="switch_bgp.grup:allow_prefixes" name="allow_prefixes" label="Dinamik komşu ağları" :id="$prefix.'-allow'"
                  class="field--wide"
                  hint="Her satıra bir ağ: 10.0.0.0/24. Boş bırakılırsa yalnızca tek tek tanımlanan komşular kabul edilir.">
        <x-form.textarea name="allow_prefixes" :id="$prefix.'-allow'" rows="3"
                         class="input--mono" :value="$group?->allow_prefixes" />
    </x-form.field>

    <x-form.field rule="switch_bgp.grup:description" name="description" label="Açıklama" :id="$prefix.'-description'">
        <x-form.input name="description" :id="$prefix.'-description'" :value="$group?->description" />
    </x-form.field>

    {{--
        Politika listesi cihazdan okunanlardır. Serbest metin olsaydı,
        tanımsız bir ada atıf commit’i düşürürdü:
        "Policy error: Policy X referenced but not defined".
    --}}
    @if ($policyOptions === [])
        <x-ui.alert variant="info">
            Bu switchte tanımlı politika yok. İçeri alma ve dışarı verme
            politikaları ancak cihazda tanımlıysa seçilebilir.
        </x-ui.alert>
    @else
        <x-form.field rule="switch_bgp.grup:import_policies" name="import_policies" label="İçeri alma politikaları"
                      hint="Komşudan gelen prefixlere uygulanır. Sıra önemlidir.">
            @foreach ($policyOptions as $policy)
                <label class="checkbox">
                    <input type="checkbox" name="import_policies[]" value="{{ $policy }}"
                           @checked(in_array($policy, $group?->import_policies ?? [], true))>
                    <span class="mono">{{ $policy }}</span>
                </label>
            @endforeach
        </x-form.field>

        <x-form.field rule="switch_bgp.grup:export_policies" name="export_policies" label="Dışarı verme politikaları"
                      hint="Komşuya gönderilen prefixlere uygulanır.">
            @foreach ($policyOptions as $policy)
                <label class="checkbox">
                    <input type="checkbox" name="export_policies[]" value="{{ $policy }}"
                           @checked(in_array($policy, $group?->export_policies ?? [], true))>
                    <span class="mono">{{ $policy }}</span>
                </label>
            @endforeach
        </x-form.field>
    @endif

    {{-- Satırlar sınıf bileşeninde hazırlanır; burada yalnızca basılır. --}}
    @foreach ($familyRows as ['key' => $family, 'label' => $label, 'current' => $current])

        <x-form.field :name="'families.'.$family" :label="$label.' taşı'" :id="$prefix.'-'.$family"
                      hint="Grup bu adres ailesini taşısın mı?">
            <label class="checkbox">
                <input type="checkbox" name="families[{{ $family }}][enabled]"
                       id="{{ $prefix }}-{{ $family }}" value="1" @checked($current !== null)>
                <span>{{ $label }} prefixleri taşınsın</span>
            </label>
        </x-form.field>

        <div data-bgp-approval="families[{{ $family }}][enabled]" class="stack">
            <x-form.field :name="'families.'.$family.'.nlri'" label="NLRI"
                          :id="$prefix.'-'.$family.'-nlri'"
                          hint="Cihazdaki yazım biçimi. Değiştirilmezse olduğu gibi korunur.">
                <x-form.select :name="'families['.$family.'][nlri]'" :id="$prefix.'-'.$family.'-nlri'"
                               :options="$nlriOptions" :selected="$current?->nlri->value ?? 'any'" />
            </x-form.field>

            <x-form.field :name="'families.'.$family.'.prefix_limit_maximum'" label="Prefix sınırı"
                          :id="$prefix.'-'.$family.'-limit'"
                          hint="Boş bırakılırsa sınır yoktur.">
                <x-form.input :name="'families['.$family.'][prefix_limit_maximum]'"
                              :id="$prefix.'-'.$family.'-limit'" type="number"
                              :value="$current?->prefix_limit_maximum" min="1" />
            </x-form.field>

            <x-form.field :name="'families.'.$family.'.teardown'" label="Sınır aşılınca"
                          :id="$prefix.'-'.$family.'-teardown'">
                <label class="checkbox">
                    <input type="checkbox" name="families[{{ $family }}][teardown]"
                           id="{{ $prefix }}-{{ $family }}-teardown" value="1" @checked($current?->teardown)>
                    <span>Oturumu düşür</span>
                </label>
            </x-form.field>

            <div data-bgp-approval="families[{{ $family }}][teardown]" class="stack">
                {{--
                    Junos bu alanı YÜZDE olarak alır (1–100) ve prefix sayısı
                    kabul etmez: 150 verildiğinde "Value 150 is not within
                    range (1..100)" der. Oturum sınır aşılınca düşer; yüzde
                    uyarının nerede başlayacağını söyler.
                --}}
                <x-form.field :name="'families.'.$family.'.teardown_threshold'" label="Uyarı eşiği (%)"
                              :id="$prefix.'-'.$family.'-esik'"
                              hint="Sınırın yüzde kaçında uyarı başlasın (1–100). Oturum sınır aşılınca düşer. Örnek: sınır 200, eşik 90 → 180 prefixte uyarı.">
                    <x-form.input :name="'families['.$family.'][teardown_threshold]'"
                                  :id="$prefix.'-'.$family.'-esik'" type="number"
                                  :value="$current?->teardown_threshold" min="1" max="100" />
                </x-form.field>

                <x-form.field :name="'families.'.$family.'.idle_timeout_forever'" label="Düştükten sonra"
                              :id="$prefix.'-'.$family.'-forever'">
                    <label class="checkbox">
                        <input type="checkbox" name="families[{{ $family }}][idle_timeout_forever]"
                               id="{{ $prefix }}-{{ $family }}-forever" value="1"
                               @checked($current?->idle_timeout_forever)>
                        <span>Elle açılana kadar kapalı kalsın</span>
                    </label>
                </x-form.field>

                {{-- Junos ikisini birden yazamaz: ya süresiz ya da verilen dakika. --}}
                <x-form.field :name="'families.'.$family.'.idle_timeout_minutes'" label="Yeniden deneme (dakika)"
                              :id="$prefix.'-'.$family.'-dakika'"
                              data-bgp-approval-not="families[{{ $family }}][idle_timeout_forever]"
                              hint="Boş bırakılırsa cihazın varsayılanı geçerlidir.">
                    <x-form.input :name="'families['.$family.'][idle_timeout_minutes]'"
                                  :id="$prefix.'-'.$family.'-dakika'" type="number"
                                  :value="$current?->idle_timeout_minutes" min="1" max="2400" />
                </x-form.field>
            </div>
        </div>
    @endforeach
</div>
