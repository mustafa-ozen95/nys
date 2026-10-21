import { $$, on } from '../lib/dom.js';

/**
 * ae formunun görünürlüğü.
 *
 * İki koşul vardır ve ikisi de Junos kısıtıdır:
 *   - LACP periyodu yalnızca bir LACP rolü seçiliyken sorulur.
 *   - Etiketsiz VLAN yalnızca trunk modunda sorulur; access portta tek üye
 *     zaten etiketsizdir.
 *
 * Görünürlük TEK yerde hesaplanır ve gizlenen alanın girdileri devre dışı
 * bırakılır: açık kalan alan sunucuya çelişkili değer gönderir ve istek
 * reddedilir (LinkAggregationRequest aynı kuralları uygular).
 */

function deger(form, ad) {
    return form.querySelector(`[name="${ad}"]`)?.value ?? '';
}

function uygula(form) {
    const lacpAcik = deger(form, 'lacp_mode') !== '';
    const trunk = deger(form, 'port_mode') === 'trunk';

    $$('[data-ae-lacp], [data-ae-trunk]', form).forEach((eleman) => {
        const acik = eleman.hasAttribute('data-ae-lacp') ? lacpAcik : trunk;

        eleman.hidden = !acik;

        $$('input, select, textarea', eleman).forEach((girdi) => {
            girdi.disabled = !acik;
        });
    });
}

export function init() {
    $$('[data-ae-formu]').forEach(uygula);

    on('change', '[data-ae-formu]', (event, form) => uygula(form));
}
