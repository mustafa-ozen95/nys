import { on } from '../lib/dom.js';

/**
 * Listeden seç / elle gir kutusu (bkz. x-form.picker, x-form.ip-picker).
 *
 * Tek işi var: "Listede yok — elle gir" seçilince metin kutusunu açmak ve
 * "name" niteliğini ona devretmek. Her an yalnızca bir kutu gönderilir;
 * ikisi birden açık kalırsa sunucuya iki değer gider.
 *
 * Doğrulama burada yapılmaz — değerin geçerliliğine sunucu karar verir.
 */

const MANUAL = '__elle__';

function apply(box, focusOn = false) {
    const list = box?.querySelector('[data-selection-list]');
    const manual = box?.querySelector('[data-selection-manual]');

    if (!list || !manual) {
        return;
    }

    const name = list.dataset.selectionList;
    const open = list.value === MANUAL;

    manual.hidden = !open;

    if (open) {
        list.removeAttribute('name');
        manual.name = name;

        if (focusOn) {
            manual.focus();
        }

        return;
    }

    list.name = name;
    manual.removeAttribute('name');

    // Kapanan kutu değerini bırakmaz: sonraki açılışta eski değer kalmasın.
    manual.value = '';
}

export function init() {
    on('change', '[data-selection-list]', (event, list) => {
        apply(list.closest('[data-selection]'), true);
    });
}
