import { on } from '../lib/dom.js';

/**
 * Bir metin alanının içeriğini panoya kopyalar.
 *
 * Uzun metinler kopyala-yapıştırla taşınır; elle seçtirmek satır kaçırmaya
 * açık.
 *
 * Pano API'si güvenli olmayan bağlamda ya da izin verilmediğinde çalışmaz;
 * o durumda metin seçilir ve kullanıcı kendi kopyalar.
 */
async function copy(button) {
    const source = document.querySelector(button.dataset.copy);

    if (!source) {
        return;
    }

    const oldText = button.textContent;

    try {
        await navigator.clipboard.writeText(source.value);
        button.textContent = 'Kopyalandı';
    } catch {
        source.focus();
        source.select();
        button.textContent = 'Seçildi, kopyalayın';
    }

    setTimeout(() => {
        button.textContent = oldText;
    }, 2000);
}

export function init() {
    on('click', '[data-copy]', (event, button) => {
        copy(button).catch(() => {});
    });
}
