{{--
    Ayarlar → Basit mod: switch bölümleri ve içlerindeki alanlar.

    Düz bir liste 28 bölüm anahtarı ve 167 alanla okunamazdı. Her bölüm bir
    kart: başlıkta "görüntülenir / yönetilir", altında katlanmış alan listesi.
    Alanlar varsayılandan farklıysa sayısı başlıkta yazar; kısıtın nerede
    olduğu kart açılmadan görülür.

    Gönderim mevcut ayar ucuna gider (SettingsRequest): her kutu gizli bir
    "0" ile birlikte basılır, işaretsiz kutu da "kapalı" olarak kaydedilir.
--}}
@php
    $ogeler = collect($group['items'])->keyBy('key');
    $kutu = function ($item) use ($values) {
        return ['ad' => $item->field(), 'acik' => (bool) old($item->field(), $values[$item->key])];
    };
@endphp

<div class="basit-mod">
    @foreach (config('basit_mod.bolumler') as $bolum => $_)
        @php
            $gor = $ogeler["basit_mod.bolumler.{$bolum}.goruntule"] ?? null;
            $yon = $ogeler["basit_mod.bolumler.{$bolum}.yonet"] ?? null;
            $alanlar = collect(config("basit_mod.alanlar.{$bolum}", []))
                ->map(fn ($tanim, $alan) => ['item' => $ogeler["basit_mod.alanlar.{$bolum}.{$alan}.acik"] ?? null, 'varsayilan' => (bool) $tanim['varsayilan']])
                ->filter(fn ($a) => $a['item'] !== null);
            $farkli = $alanlar->filter(fn ($a) => (bool) $values[$a['item']->key] !== $a['varsayilan'])->count();
        @endphp

        @continue ($gor === null || $yon === null)

        <section class="basit-mod__bolum">
            <header class="basit-mod__bas">
                <strong class="basit-mod__ad">{{ config("permissions.resources.{$bolum}.label", $bolum) }}</strong>

                <span class="basit-mod__anahtarlar">
                    @foreach ([[$gor, 'Görüntülenir'], [$yon, 'Yönetilir']] as [$item, $etiket])
                        @php($k = $kutu($item))
                        <label class="checkbox" title="{{ $item->hint }}">
                            <input type="hidden" name="{{ $k['ad'] }}" value="0">
                            <input type="checkbox" name="{{ $k['ad'] }}" value="1" @checked($k['acik'])>
                            <span>{{ $etiket }}</span>
                        </label>
                    @endforeach
                </span>
            </header>

            @if ($alanlar->isNotEmpty())
                <details class="basit-mod__alanlar" @if ($errors->any()) open @endif>
                    <summary>
                        Alanlar ({{ $alanlar->count() }})
                        @if ($farkli > 0)
                            <span class="badge badge--yellow">{{ $farkli }} alan varsayılandan farklı</span>
                        @endif
                    </summary>

                    <div class="basit-mod__izgara">
                        @foreach ($alanlar as $a)
                            @php($k = $kutu($a['item']))
                            <label class="checkbox">
                                <input type="hidden" name="{{ $k['ad'] }}" value="0">
                                <input type="checkbox" name="{{ $k['ad'] }}" value="1" @checked($k['acik'])>
                                <span>{{ $a['item']->label }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="text-sm text-muted">İşaretsiz alan basit modda görünmez; cihazdaki değeri korunur, yalnızca değiştirilemez.</p>
                </details>
            @endif
        </section>
    @endforeach
</div>
