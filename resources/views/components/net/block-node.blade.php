{{--
    Ağaçtaki bir blok ve altındaki yerleşim.

    <details> KULLANILIR, JavaScript DEĞİL: satır tıklanınca yerinde açılıyor,
    betik yüklenmese de çalışıyor ve açık/kapalı durumu tarayıcının kendi işi.
    Açılma animasyonu CSS'te.

    Kendini çağırır: /24 → /27 → /29 aynı biçimde çizilir.
--}}
@props(['dugum', 'selected' => null])

@if ($dugum['satirlar'] !== [])
    <details class="agac__dal" @if ($selected && ($selected->is($dugum['block']) || $selected->parent?->is($dugum['block']))) open @endif>
        <summary class="agac__ozet">
            <x-net.block-row :block="$dugum['block']" :selected="$selected" />
        </summary>

        <div class="agac__ic">
            @foreach ($dugum['satirlar'] as $satir)
                @if ($satir['tur'] === 'alt-ag')
                    @isset($satir['alt'])
                        <x-dynamic-component component="net.block-node" :dugum="$satir['alt']" :selected="$selected" />
                    @else
                        <x-net.block-row :block="$satir['blok']" :selected="$selected" />
                    @endisset
                @else
                    {{--
                        BOŞLUK. Tanımlı bir kaydı yok; bloğun içinde kimseye
                        verilmemiş yer. Hangi maskelerin sığdığı yazar çünkü
                        kullanıcı oraya alt ağ açacak.
                    --}}
                    <div class="grid-row grid-row--static block-row agac__bosluk">
                        <span class="mono block-row__cidr">{{ $satir['cidr'] }}</span>
                        <span class="text-sm text-muted truncate block-row__aciklama">
                            Boşta · sığan bloklar:
                            <span class="mono">{{ implode(', ', $satir['parcalar']) }}</span>
                        </span>
                        <span class="block-row__atama assignment--bos">—</span>
                        <span class="text-sm text-muted block-row__sayi">
                            {{ $satir['adres'] === null ? '' : number_format($satir['adres'], 0, ',', '.').' adres' }}
                        </span>
                    </div>
                @endif
            @endforeach

            @if ($dugum['kesildi'])
                <p class="agac__kesildi text-sm text-muted">
                    Liste kısaltıldı. Devamı için bloğa tıklayın.
                </p>
            @endif
        </div>
    </details>
@else
    <x-net.block-row :block="$dugum['block']" :selected="$selected" />
@endif
