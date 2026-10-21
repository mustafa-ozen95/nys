@props(['at' => null, 'format' => 'd.m.Y H:i:s', 'fallback' => '—'])

{{--
    Zaman GÖSTERİMİ.

    Saklama UTC'dir ve öyle kalır: geçmiş kayıtları yeniden yorumlamak, hash
    zincirine giren denetim kayıtlarının anlamını değiştirirdi. Değişen
    yalnızca gösterimdir — panel Türkiye saatinde okunuyor, UTC değil.

    Tek yerden çevrilir: her ekranın kendi ->format() çağrısı olsaydı biri
    unutulur ve iki farklı saat yan yana görünürdü.
--}}
@if ($at === null)
    {{ $fallback }}
@else
    <time datetime="{{ $at->toIso8601String() }}"
          title="{{ $at->timezone(config('app.display_timezone'))->format('d.m.Y H:i:s T') }}"
          {{ $attributes }}>{{ $at->timezone(config('app.display_timezone'))->format($format) }}</time>
@endif
