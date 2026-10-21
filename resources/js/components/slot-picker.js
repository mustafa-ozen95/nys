import { $$, on } from '../lib/dom.js';
import { http } from '../lib/http.js';

/**
 * Alt ağ eklerken boş yuvaları listeler.
 *
 * Kullanıcı adres yazmaz: maskeyi seçer, sunucunun boş bulduğu yuvalardan
 * birini seçer. Bölme ve doluluk hesabı sunucudadır.
 */

function doldur(select, yuvalar) {
    const bos = document.createElement('option');
    bos.value = '';
    bos.textContent = yuvalar.length ? 'Seçiniz' : 'Boş yuva kalmadı';

    const secenekler = yuvalar.map((yuva) => {
        const option = document.createElement('option');
        option.value = yuva.network;
        option.textContent = `${yuva.cidr} · ${yuva.usable} adres`;

        return option;
    });

    select.replaceChildren(bos, ...secenekler);
    select.disabled = yuvalar.length === 0;
}

async function yukle(maske) {
    const form = maske.closest('form');
    const select = form?.querySelector('[data-yuva-secim]');

    if (!select) {
        return;
    }

    if (!maske.value) {
        doldur(select, []);

        return;
    }

    const yanit = await http.get(`${maske.dataset.yuvaUcu}?prefix=${maske.value}`);

    doldur(select, yanit.slots.filter((yuva) => yuva.available));
}

export function init() {
    $$('[data-yuva-maske]').forEach((maske) => yukle(maske).catch(() => {}));

    on('change', '[data-yuva-maske]', (event, maske) => {
        yukle(maske).catch(() => {});
    });
}
