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

const ELLE = '__elle__';

function uygula(kutu, odakla = false) {
    const liste = kutu?.querySelector('[data-secim-liste]');
    const elle = kutu?.querySelector('[data-secim-elle]');

    if (!liste || !elle) {
        return;
    }

    const ad = liste.dataset.secimListe;
    const acik = liste.value === ELLE;

    elle.hidden = !acik;

    if (acik) {
        liste.removeAttribute('name');
        elle.name = ad;

        if (odakla) {
            elle.focus();
        }

        return;
    }

    liste.name = ad;
    elle.removeAttribute('name');

    // Kapanan kutu değerini bırakmaz: sonraki açılışta eski değer kalmasın.
    elle.value = '';
}

export function init() {
    on('change', '[data-secim-liste]', (event, liste) => {
        uygula(liste.closest('[data-secim]'), true);
    });
}
