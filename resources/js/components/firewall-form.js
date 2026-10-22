import { $$, on } from '../lib/dom.js';

/**
 * Yeni firewall filtresi penceresi.
 *
 * Junos'ta koşullar AİLEYE göre değişir: "next-header" yalnızca IPv6'da,
 * "ether-type" ve MAC adresleri yalnızca ethernet-switching'de, IPv4 başlık
 * alanları (precedence, ip-options, parça bayrakları) yalnızca inet'te
 * bulunur. Yanlış ailede gönderilen koşulu cihaz reddeder; panel onu hiç
 * sormaz.
 *
 * Terim pencerelerinde aile zaten bellidir ve sunucu doğru bloğu basar;
 * burası yalnızca ailenin SEÇİLDİĞİ pencere için gerekir.
 *
 * Aynı kısıt sunucuda da vardır (FirewallTermRequest::guardFamily) — alanı
 * gizlemek koruma değildir, istek doğrudan da gelebilir.
 */
function applyFamily(select) {
    const window = select.closest('.modal, form');

    if (! window) {
        return;
    }

    $$('[data-fw-family]', window).forEach((block) => {
        block.classList.toggle('is-hidden', block.dataset.fwFamily !== select.value);
    });
}

export function init() {
    $$('[data-fw-family]').forEach(applyFamily);

    on('change', '[data-fw-family]', (event, select) => applyFamily(select));
}
