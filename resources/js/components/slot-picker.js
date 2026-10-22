import { $$, on } from '../lib/dom.js';
import { http } from '../lib/http.js';

/**
 * Alt ağ eklerken boş yuvaları listeler.
 *
 * Kullanıcı adres yazmaz: maskeyi seçer, sunucunun boş bulduğu yuvalardan
 * birini seçer. Bölme ve doluluk hesabı sunucudadır.
 */

function fill(select, slots) {
    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = slots.length ? 'Seçiniz' : 'Boş yuva kalmadı';

    const options = slots.map((slot) => {
        const option = document.createElement('option');
        option.value = slot.network;
        option.textContent = `${slot.cidr} · ${slot.usable} adres`;

        return option;
    });

    select.replaceChildren(empty, ...options);
    select.disabled = slots.length === 0;
}

async function load(mask) {
    const form = mask.closest('form');
    const select = form?.querySelector('[data-slot-selection]');

    if (!select) {
        return;
    }

    if (!mask.value) {
        fill(select, []);

        return;
    }

    const response = await http.get(`${mask.dataset.slotEnd}?prefix=${mask.value}`);

    fill(select, response.slots.filter((slot) => slot.available));
}

export function init() {
    $$('[data-slot-mask]').forEach((mask) => load(mask).catch(() => {}));

    on('change', '[data-slot-mask]', (event, mask) => {
        load(mask).catch(() => {});
    });
}
