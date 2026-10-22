import { $$, on } from '../lib/dom.js';

/**
 * Config yükleme formunda alanlar seçime bağlıdır.
 *
 * Hedef envanterdeki cihazsa dış cihaz alanları, elle yapıştırılıyorsa
 * şablon girdileri anlamsızdır. Hepsi birden görünürse ekran, hangi alanın
 * gerçekten kullanıldığı belli olmayan bir liste hâline gelir.
 *
 * Gizlenen alanın girdileri devre dışı bırakılır: aksi hâlde form kullanmadığı
 * değerleri de gönderir ve sunucu çelişkili bir istek alır (hem envanterdeki
 * cihaz hem dış cihaz gibi).
 */
function apply(form) {
    const target = form.querySelector('[name="target"]')?.value ?? 'switch';
    const source = form.querySelector('[name="source"]')?.value ?? 'manual';

    show(form, '[data-target]', (field) => field.dataset.target === target);
    show(form, '[data-source]', (field) => field.dataset.source === source);
}

function show(form, selector, condition) {
    form.querySelectorAll(selector).forEach((field) => {
        const visible = condition(field);

        field.hidden = !visible;
        field.querySelectorAll('input, select, textarea').forEach((input) => {
            input.disabled = !visible;
        });
    });
}

export function init() {
    const forms = $$('[data-config-form]');

    if (forms.length === 0) {
        return;
    }

    forms.forEach(apply);

    // on(): (olay, seçici, işleyici) — kapsam belge geneli.
    on('change', '[data-config-form] [name="target"], [data-config-form] [name="source"]', (event, selector) => {
        const form = selector.closest('[data-config-form]');

        if (form) {
            apply(form);
        }
    });
}
