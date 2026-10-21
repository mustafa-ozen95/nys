@props([
    'neighbor' => null,
    'prefix' => 'yeni',
    'peeringAddressOptions' => [],
    'policyOptions' => [],
])

<div class="modal__body stack">
    {{--
        Adresler Adresleme'de peering ve uplink için ayrılmış blokların
        içinden seçilir; liste blok başlığına göre gruplanır, oturumun iki ucu
        aynı grubun içindedir.

        Elle giriş bilinçli olarak açıktır ve tek istisnadır: sağlayıcının
        kendi adresi havuzumuzda yer almaz.
    --}}
    {{--
        Adres cihazda komşunun KİMLİĞİDİR; grup adı gibi sonradan
        değiştirilemez. Düzenlemede salt okunur gösterilir, sunucu da
        değişmiş bir adresi reddeder.
    --}}
    @if ($neighbor === null)
        <x-form.field kural="switch_bgp.komsu:address" name="address" label="Komşu adresi" :id="$prefix.'-adres'"
                      hint="Cihazda bu adres switch genelinde benzersizdir ve sonradan değiştirilemez.">
            <x-form.ip-picker name="address" :id="$prefix.'-adres'" :options="$peeringAddressOptions"
                              manual placeholder="198.18.8.37"
                              empty-text="Adresleme’de peering veya uplink bloğu yok; adresi elle yazın." />
        </x-form.field>
    @else
        <x-form.field kural="switch_bgp.komsu:address" name="address" label="Komşu adresi" :id="$prefix.'-adres'"
                      hint="Cihazda kimliktir; değişecekse komşu silinip yeniden eklenir.">
            <x-form.input name="address" :id="$prefix.'-adres'" :value="$neighbor->address" mono readonly />
        </x-form.field>
    @endif

    <x-form.field kural="switch_bgp.komsu:description" name="description" label="Açıklama" :id="$prefix.'-aciklama'">
        <x-form.input name="description" :id="$prefix.'-aciklama'" :value="$neighbor?->description" />
    </x-form.field>

    {{--
        Buradan aşağısı grubun değerini EZER. Junos grup ayarlarının hemen
        hepsini komşu seviyesinde de kabul eder; boş bırakılan alanda grubun
        değeri geçerli kalır.
    --}}
    <x-form.field kural="switch_bgp.komsu:peer_asn" name="peer_asn" label="Karşı taraf AS (isteğe bağlı)" :id="$prefix.'-peer'"
                  hint="Boş bırakılırsa grubun AS numarası kullanılır.">
        <x-form.input name="peer_asn" :id="$prefix.'-peer'" :value="$neighbor?->peer_asn" mono />
    </x-form.field>

    <x-form.field kural="switch_bgp.komsu:is_inactive" name="is_inactive" label="Devre dışı (deactivate)" :id="$prefix.'-inactive'"
                  hint="Komşu tanımı cihazda kalır ama oturum kurulmaz.">
        <label class="checkbox">
            <input type="hidden" name="is_inactive" value="0">
            <input type="checkbox" name="is_inactive" id="{{ $prefix }}-inactive"
                   value="1" @checked($neighbor?->is_inactive)>
            <span>Bu komşu devre dışı olsun</span>
        </label>
    </x-form.field>

    {{--
        Ayrıntılar KATLI durur.

        Komşu eklemek ve devre dışı bırakmak günlük iştir; hold-time,
        multihop ve politikalara yılda birkaç kez dokunulur. Alanlar
        GİZLENMEZ, katlanır: burada "gönderilmedi" cihazdaki değerin silinmesi
        demektir, dolayısıyla alanın DOM'da kalması gerekir. Dolu bir ayrıntı
        varsa grup açık gelir — ekran cihazdaki gerçeği saklamaz.
    --}}
    <details class="fieldgroup" @if ($neighbor?->hasDetailSettings() || $errors->any()) open @endif>
        <summary class="fieldgroup__head">Ayrıntılar (yerel adres, anahtar, hold-time, multihop, politikalar)</summary>

        <div class="fieldgroup__body stack">
        <x-form.field kural="switch_bgp.komsu:local_address" name="local_address" label="Yerel adres (isteğe bağlı)" :id="$prefix.'-yerel'"
                      hint="Boş bırakılırsa grubun yerel adresi kullanılır.">
            <x-form.ip-picker name="local_address" :id="$prefix.'-yerel'" :options="$peeringAddressOptions"
                              :value="$neighbor?->local_address" manual placeholder="198.18.8.38"
                              empty-text="Adresleme’de peering veya uplink bloğu yok; adresi elle yazın." />
        </x-form.field>

        {{--
            MD5 anahtarı çoğu zaman KOMŞU BAŞINA farklıdır: her sağlayıcı kendi
            anahtarını verir. Parola gibi davranır.
        --}}
        <x-form.field kural="switch_bgp.komsu:authentication_key" name="authentication_key" label="Oturum anahtarı (MD5)" :id="$prefix.'-anahtar'"
                      :hint="$neighbor?->authentication_key === null
                          ? 'Boş bırakılırsa grubun anahtarı geçerlidir.'
                          : 'Kayıtlı anahtar var. Boş bırakılırsa korunur.'">
            <x-form.input type="password" name="authentication_key" :id="$prefix.'-anahtar'"
                          autocomplete="new-password" mono />
        </x-form.field>

        @if ($neighbor?->authentication_key !== null)
            <x-form.field kural="switch_bgp.komsu:clear_authentication_key" name="clear_authentication_key" label="Kayıtlı anahtar" :id="$prefix.'-anahtar-sil'"
                          hint="Silinirse grubun anahtarı (varsa) geçerli olur.">
                <label class="checkbox">
                    <input type="hidden" name="clear_authentication_key" value="0">
                    <input type="checkbox" name="clear_authentication_key" id="{{ $prefix }}-anahtar-sil" value="1">
                    <span>Anahtarı sil</span>
                </label>
            </x-form.field>
        @endif

        <x-form.field kural="switch_bgp.komsu:hold_time" name="hold_time" label="Hold-time (saniye)" :id="$prefix.'-hold'"
                      hint="Boş bırakılırsa grubun ya da cihazın değeri geçerlidir.">
            <x-form.input name="hold_time" :id="$prefix.'-hold'" type="number"
                          :value="$neighbor?->hold_time" min="3" max="65535" />
        </x-form.field>

        <x-form.field kural="switch_bgp.komsu:passive" name="passive" label="Pasif" :id="$prefix.'-passive'"
                      hint="Oturumu biz başlatmayız, karşı tarafın bağlanmasını bekleriz.">
            <label class="checkbox">
                <input type="hidden" name="passive" value="0">
                <input type="checkbox" name="passive" id="{{ $prefix }}-passive"
                       value="1" @checked($neighbor?->passive)>
                <span>Bağlantıyı karşı taraf başlatsın</span>
            </label>
        </x-form.field>

        <x-form.field kural="switch_bgp.komsu:multihop" name="multihop" label="Multihop" :id="$prefix.'-multihop'"
                      hint="Komşu doğrudan bağlı değilse gerekir.">
            <label class="checkbox">
                <input type="hidden" name="multihop" value="0">
                <input type="checkbox" name="multihop" id="{{ $prefix }}-multihop"
                       value="1" @checked($neighbor?->multihop)>
                <span>Komşu doğrudan bağlı değil</span>
            </label>
        </x-form.field>

        {{-- TTL yalnızca multihop açıkken sorulur; kapalıyken cihaz reddeder. --}}
        <x-form.field kural="switch_bgp.komsu:multihop_ttl" name="multihop_ttl" label="Multihop TTL" :id="$prefix.'-ttl'"
                      data-bgp-onay="multihop"
                      hint="Boş bırakılırsa cihazın varsayılanı kullanılır.">
            <x-form.input name="multihop_ttl" :id="$prefix.'-ttl'" type="number"
                          :value="$neighbor?->multihop_ttl" min="1" max="255" />
        </x-form.field>

        {{--
            Politika listesi cihazdan okunanlardır. Serbest metin olsaydı,
            tanımsız bir ada atıf commit’i düşürürdü.
        --}}
        @if ($policyOptions !== [])
            <x-form.field kural="switch_bgp.komsu:import_policies" name="import_policies" label="İçeri alma politikaları (isteğe bağlı)"
                          hint="Boş bırakılırsa grubun politikaları geçerlidir. Sıra önemlidir.">
                @foreach ($policyOptions as $policy)
                    <label class="checkbox">
                        <input type="checkbox" name="import_policies[]" value="{{ $policy }}"
                               @checked(in_array($policy, $neighbor?->import_policies ?? [], true))>
                        <span class="mono">{{ $policy }}</span>
                    </label>
                @endforeach
            </x-form.field>

            <x-form.field kural="switch_bgp.komsu:export_policies" name="export_policies" label="Dışarı verme politikaları (isteğe bağlı)"
                          hint="Boş bırakılırsa grubun politikaları geçerlidir.">
                @foreach ($policyOptions as $policy)
                    <label class="checkbox">
                        <input type="checkbox" name="export_policies[]" value="{{ $policy }}"
                               @checked(in_array($policy, $neighbor?->export_policies ?? [], true))>
                        <span class="mono">{{ $policy }}</span>
                    </label>
                @endforeach
            </x-form.field>
        @endif
        </div>
    </details>
</div>
