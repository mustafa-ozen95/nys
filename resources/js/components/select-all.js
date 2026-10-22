import { $$, on } from '../lib/dom.js';

/**
 * "Tümü" kutusu: bulunduğu grubun tüm onay kutularını birlikte işaretler.
 *
 * Grup [data-selection-group] ile işaretlenir; ana kutu [data-select-all] taşır.
 * Tek tek seçim değişince ana kutu da güncellenir, aksi halde işaretli
 * görünürken alt kutular boş kalabilir.
 */

function subBoxes(group) {
    return [...group.querySelectorAll('input[type="checkbox"]:not([data-select-all])')];
}

function updateMainBox(group) {
    const main = group.querySelector('[data-select-all]');
    const boxes = subBoxes(group);

    if (!main || boxes.length === 0) {
        return;
    }

    const selected = boxes.filter((box) => box.checked).length;

    main.checked = selected === boxes.length;
    main.indeterminate = selected > 0 && selected < boxes.length;
}

export function init() {
    $$('[data-selection-group]').forEach(updateMainBox);

    on('change', '[data-selection-group] [data-select-all]', (event, main) => {
        subBoxes(main.closest('[data-selection-group]')).forEach((box) => {
            box.checked = main.checked;
        });
    });

    on('change', '[data-selection-group] input[type="checkbox"]:not([data-select-all])', (event, box) => {
        updateMainBox(box.closest('[data-selection-group]'));
    });
}
