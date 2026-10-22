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

function value(form, name) {
    return form.querySelector(`[name="${name}"]`)?.value ?? '';
}

function apply(form) {
    const lacpOpen = value(form, 'lacp_mode') !== '';
    const trunk = value(form, 'port_mode') === 'trunk';

    $$('[data-ae-lacp], [data-ae-trunk]', form).forEach((element) => {
        const open = element.hasAttribute('data-ae-lacp') ? lacpOpen : trunk;

        element.hidden = !open;

        $$('input, select, textarea', element).forEach((input) => {
            input.disabled = !open;
        });
    });
}

export function init() {
    $$('[data-ae-form]').forEach(apply);

    on('change', '[data-ae-form]', (event, form) => apply(form));
}
