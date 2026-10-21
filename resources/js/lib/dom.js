/**
 * Küçük DOM yardımcıları. İş mantığı içermez.
 */

export const $ = (selector, scope = document) => scope.querySelector(selector);

export const $$ = (selector, scope = document) => Array.from(scope.querySelectorAll(selector));

/**
 * Halihazırda kurulmuş delegasyonlar. Anahtar: olay + seçici + işleyicinin
 * KAYNAK METNİ.
 */
const kurulanlar = new WeakMap();

/**
 * Olay delegasyonu: sonradan eklenen düğümler için de çalışır.
 *
 * AYNI delegasyon iki kez kurulmaz. Sayfa geçişleri tarayıcıyı yenilemediği
 * için (bkz. navigation.js) bileşenlerin init'i her çizimde yeniden koşar;
 * korumasız bırakılsaydı her tıklama iki, üç, dört kez işlenirdi.
 *
 * Anahtara işleyicinin kaynak metni de girer: yeniden oluşturulan aynı
 * kapanışın metni aynıdır, yani tekrar sayılır — ama aynı olay ve seçiciyi
 * kullanan FARKLI bir işleyici birbirini ezmez.
 */
export function on(eventName, selector, handler, scope = document) {
    if (! kurulanlar.has(scope)) {
        kurulanlar.set(scope, new Set());
    }

    const kayit = kurulanlar.get(scope);
    const anahtar = `${eventName}|${selector}|${handler.toString()}`;

    if (kayit.has(anahtar)) {
        return;
    }

    kayit.add(anahtar);

    scope.addEventListener(eventName, (event) => {
        const target = event.target.closest(selector);

        if (target && scope.contains(target)) {
            handler(event, target);
        }
    });
}

export function escapeHtml(value) {
    const element = document.createElement('span');
    element.textContent = value ?? '';

    return element.innerHTML;
}
