import { $$, on } from '../lib/dom.js';

/**
 * IP adresi formunda önce bağlantı türü seçilir.
 *
 * Adres ya bir VLAN'ın L3 arayüzünde ya doğrudan bir portta ya da ae0.0
 * gibi başka bir arayüzde durur. Seçim yapılmadan hiçbir alan gösterilmez;
 * seçilmeyen alanlar gizlenir ve boşaltılır, aksi halde sunucuya birden
 * fazla bağlantı gider.
 */

function apply(form) {
    const selected = form.querySelector('[data-ip-attachment] input:checked');
    const type = selected ? selected.value : null;

    form.querySelectorAll('[data-ip-field]').forEach((field) => {
        const visible = field.dataset.ipField === type;

        field.hidden = ! visible;

        if (! visible) {
            field.querySelectorAll('select, input').forEach((input) => {
                input.value = '';
            });
        }
    });
}

export function init() {
    $$('[data-ip-attachment]').forEach((selector) => apply(selector.closest('form')));

    on('change', '[data-ip-attachment] input', (event, input) => {
        apply(input.closest('form'));
    });
}
