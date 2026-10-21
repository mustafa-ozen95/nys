import { $$, on } from '../lib/dom.js';

/**
 * IP adresi formunda önce bağlantı türü seçilir.
 *
 * Adres ya bir VLAN'ın L3 arayüzünde ya doğrudan bir portta ya da ae0.0
 * gibi başka bir arayüzde durur. Seçim yapılmadan hiçbir alan gösterilmez;
 * seçilmeyen alanlar gizlenir ve boşaltılır, aksi halde sunucuya birden
 * fazla bağlantı gider.
 */

function uygula(form) {
    const secili = form.querySelector('[data-ip-attachment] input:checked');
    const tur = secili ? secili.value : null;

    form.querySelectorAll('[data-ip-alan]').forEach((alan) => {
        const gorunur = alan.dataset.ipAlan === tur;

        alan.hidden = ! gorunur;

        if (! gorunur) {
            alan.querySelectorAll('select, input').forEach((girdi) => {
                girdi.value = '';
            });
        }
    });
}

export function init() {
    $$('[data-ip-attachment]').forEach((secici) => uygula(secici.closest('form')));

    on('change', '[data-ip-attachment] input', (event, girdi) => {
        uygula(girdi.closest('form'));
    });
}
