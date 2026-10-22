import { on } from '../lib/dom.js';

/**
 * Statik route penceresi.
 *
 * İki iş yapar:
 *
 * 1. Yolun ne yapacağı seçilince atlama alanları görünür/gizlenir. "Discard",
 *    "reject" ve "receive" sonraki atlama almaz; alan açık kalırsa kullanıcı
 *    doldurur ve sunucu "bu alan yasak" der.
 * 2. Tekrarlanan alanlar (atlamalar, yedek atlamalar, community) eklenip
 *    çıkarılabilir. ECMP tam olarak budur: aynı route'a birden çok atlama.
 *
 * Kurallar sunucuda da vardır (SwitchRouteRequest); buradaki yalnızca
 * görünürlük ve kolaylıktır.
 */
function applyAction(select) {
    const window = select.closest('.modal, form');
    const field = window?.querySelector('[data-route-hop]');

    if (field) {
        field.classList.toggle('is-hidden', select.value !== 'next-hop');
    }
}

/**
 * Satırı kopyalayıp temizler. Dizi adları ("next_hops[]") olduğu gibi kalır;
 * indeksli adlar (qualified_next_hops[0][name]) yeniden numaralanır.
 */
function addRow(button) {
    const container = button.closest('[data-repeat]');
    const lastRow = container?.querySelector('[data-repeat-row]:last-of-type');

    if (! container || ! lastRow) {
        return;
    }

    const fresh = lastRow.cloneNode(true);

    fresh.querySelectorAll('input, select').forEach((input) => {
        input.value = '';
    });

    container.insertBefore(fresh, button);
    renumber(container);
}

function removeRow(button) {
    const container = button.closest('[data-repeat]');
    const row = button.closest('[data-repeat-row]');

    // Son satır silinmez; boş bırakmak yeterli.
    if (container && row && container.querySelectorAll('[data-repeat-row]').length > 1) {
        row.remove();
        renumber(container);
    } else if (row) {
        row.querySelectorAll('input, select').forEach((input) => {
            input.value = '';
        });
    }
}

function renumber(container) {
    container.querySelectorAll('[data-repeat-row]').forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((input) => {
            input.name = input.name.replace(/\[\d+\]/, `[${index}]`);
        });
    });
}

export function init() {
    document.querySelectorAll('[data-route-action]').forEach(applyAction);

    on('change', '[data-route-action]', (event, select) => applyAction(select));
    on('click', '[data-repeat-add]', (event, button) => addRow(button));
    on('click', '[data-repeat-remove]', (event, button) => removeRow(button));
}
