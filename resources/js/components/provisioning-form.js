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
function uygula(form) {
    const hedef = form.querySelector('[name="target"]')?.value ?? 'switch';
    const kaynak = form.querySelector('[name="source"]')?.value ?? 'manual';

    goster(form, '[data-hedef]', (alan) => alan.dataset.hedef === hedef);
    goster(form, '[data-kaynak]', (alan) => alan.dataset.kaynak === kaynak);
}

function goster(form, secici, kosul) {
    form.querySelectorAll(secici).forEach((alan) => {
        const gorunur = kosul(alan);

        alan.hidden = !gorunur;
        alan.querySelectorAll('input, select, textarea').forEach((girdi) => {
            girdi.disabled = !gorunur;
        });
    });
}

export function init() {
    const formlar = $$('[data-config-formu]');

    if (formlar.length === 0) {
        return;
    }

    formlar.forEach(uygula);

    // on(): (olay, seçici, işleyici) — kapsam belge geneli.
    on('change', '[data-config-formu] [name="target"], [data-config-formu] [name="source"]', (event, secici) => {
        const form = secici.closest('[data-config-formu]');

        if (form) {
            uygula(form);
        }
    });
}
