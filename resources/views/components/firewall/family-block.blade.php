{{--
    Yalnızca belirli bir ailede anlamlı olan koşulları saran blok.

    Sunucu tarafı aynı kısıtı ayrıca uygular (FirewallTermRequest::guardFamily);
    alanı gizlemek koruma DEĞİLDİR, istek doğrudan da gelebilir.

    Yeni filtre penceresinde aile bir açılır kutuyla seçilir ve blok
    firewall-form.js tarafından açılıp kapanır; terim pencerelerinde aile
    zaten bellidir ve sunucu doğru bloğu basar.
--}}
@props(['family', 'only'])

<div data-fw-family="{{ $only }}" @class(['stack', 'is-hidden' => $family->value !== $only])>
    {{ $slot }}
</div>
