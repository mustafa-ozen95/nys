{{--
    Doluluk oranı bir veri değeridir, stil kararı değil: data özniteliği
    olarak geçilir, genişliği meter.js CSS değişkenine yazar. Satır içi
    style kullanılamaz; CSP style-src'de 'unsafe-inline' yok.
--}}
<span {{ $attributes->class('meter') }}>
    <span class="meter__track">
        <span class="meter__fill meter__fill--{{ $variant->value }}" data-value="{{ $percent ?? 0 }}"></span>
    </span>
    <span class="meter__value">{{ $display }}</span>
</span>
