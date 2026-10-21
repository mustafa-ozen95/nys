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
function aileyiUygula(select) {
    const pencere = select.closest('.modal, form');

    if (! pencere) {
        return;
    }

    $$('[data-fw-aile]', pencere).forEach((blok) => {
        blok.classList.toggle('is-hidden', blok.dataset.fwAile !== select.value);
    });
}

export function init() {
    $$('[data-fw-family]').forEach(aileyiUygula);

    on('change', '[data-fw-family]', (event, select) => aileyiUygula(select));
}
