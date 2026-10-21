import { $$, on } from '../lib/dom.js';

/**
 * "Tümü" kutusu: bulunduğu grubun tüm onay kutularını birlikte işaretler.
 *
 * Grup [data-secim-grubu] ile işaretlenir; ana kutu [data-tumunu-sec] taşır.
 * Tek tek seçim değişince ana kutu da güncellenir, aksi halde işaretli
 * görünürken alt kutular boş kalabilir.
 */

function altKutular(grup) {
    return [...grup.querySelectorAll('input[type="checkbox"]:not([data-tumunu-sec])')];
}

function anaKutuyuGuncelle(grup) {
    const ana = grup.querySelector('[data-tumunu-sec]');
    const kutular = altKutular(grup);

    if (!ana || kutular.length === 0) {
        return;
    }

    const secili = kutular.filter((kutu) => kutu.checked).length;

    ana.checked = secili === kutular.length;
    ana.indeterminate = secili > 0 && secili < kutular.length;
}

export function init() {
    $$('[data-secim-grubu]').forEach(anaKutuyuGuncelle);

    on('change', '[data-secim-grubu] [data-tumunu-sec]', (event, ana) => {
        altKutular(ana.closest('[data-secim-grubu]')).forEach((kutu) => {
            kutu.checked = ana.checked;
        });
    });

    on('change', '[data-secim-grubu] input[type="checkbox"]:not([data-tumunu-sec])', (event, kutu) => {
        anaKutuyuGuncelle(kutu.closest('[data-secim-grubu]'));
    });
}
