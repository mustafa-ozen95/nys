{{--
    Ayarlar → Basit mod: switch bölümleri ve içlerindeki alanlar.

    Düz bir liste 28 bölüm anahtarı ve 167 alanla okunamazdı. Her bölüm bir
    kart: başlıkta "görüntülenir / yönetilir", altında katlanmış alan listesi.
    Alanlar varsayılandan farklıysa sayısı başlıkta yazar; kısıtın nerede
    olduğu kart açılmadan görülür.

    Gönderim mevcut ayar ucuna gider (SettingsRequest): her kutu gizli bir
    "0" ile birlikte basılır, işaretsiz kutu da "kapalı" olarak kaydedilir.
--}}
<div class="basit-mod">
    @foreach ($basitMod as $bolum)
        <section class="basit-mod__bolum">
            <header class="basit-mod__bas">
                <strong class="basit-mod__ad">{{ $bolum['ad'] }}</strong>

                <span class="basit-mod__anahtarlar">
                    @foreach ($bolum['anahtarlar'] as $k)
                        <label class="checkbox" title="{{ $k['ipucu'] }}">
                            <input type="hidden" name="{{ $k['ad'] }}" value="0">
                            <input type="checkbox" name="{{ $k['ad'] }}" value="1" @checked($k['acik'])>
                            <span>{{ $k['etiket'] }}</span>
                        </label>
                    @endforeach
                </span>
            </header>

            @if ($bolum['alanlar'] !== [])
                <details class="basit-mod__alanlar" @if ($errors->any()) open @endif>
                    <summary>
                        Alanlar ({{ count($bolum['alanlar']) }})
                        @if ($bolum['farkli'] > 0)
                            <span class="badge badge--yellow">{{ $bolum['farkli'] }} alan varsayılandan farklı</span>
                        @endif
                    </summary>

                    <div class="basit-mod__izgara">
                        @foreach ($bolum['alanlar'] as $k)
                            <label class="checkbox">
                                <input type="hidden" name="{{ $k['ad'] }}" value="0">
                                <input type="checkbox" name="{{ $k['ad'] }}" value="1" @checked($k['acik'])>
                                <span>{{ $k['etiket'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="text-sm text-muted">İşaretsiz alan basit modda görünmez; cihazdaki değeri korunur, yalnızca değiştirilemez.</p>
                </details>
            @endif
        </section>
    @endforeach
</div>
