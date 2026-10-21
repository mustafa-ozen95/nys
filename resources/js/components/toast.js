import { $ } from '../lib/dom.js';

const DISPLAY_MS = 4000;

/*
 * UZUN İLETİ DAHA UZUN DURUR.
 *
 * Sabit dört saniye kısa iletiler için doğru ama "şu cihaz şu yüzden
 * güncellenemedi" gibi bir cümle o sürede okunmadan kaybolur. Süre metnin
 * uzunluğuyla büyür, bir tavanı aşmaz.
 */
const DISPLAY_MS_MAX = 12000;

function duration(message) {
    return Math.min(DISPLAY_MS + message.length * 45, DISPLAY_MS_MAX);
}

/**
 * Kısa süreli bildirim. Sunucudan gelen hazır metni gösterir, metin üretmez.
 */
export function emit(message, variant = 'info') {
    const host = $('.toast-host');

    if (!host) {
        return;
    }

    const toast = document.createElement('div');
    toast.className = `toast toast--${variant}`;
    toast.role = 'status';
    toast.textContent = message;

    /* Okuyan kişi beklemek zorunda kalmasın. */
    toast.title = 'Kapatmak için tıklayın';
    toast.addEventListener('click', () => toast.remove());

    host.append(toast);

    setTimeout(() => toast.remove(), duration(message));
}

export function init() {
    const host = $('.toast-host');
    const flash = host?.dataset.flash;

    if (flash) {
        emit(flash, host.dataset.flashVariant || 'success');

        /*
         * Bir kez gösterilir. Panelde sayfa geçişleri tam yenileme yapmıyor;
         * işaret silinmezse aynı bildirim her geçişte yeniden çıkabilir.
         * Burada gövde tümüyle değiştiği için tetiklenmiyor ama işaretin
         * silinmesi, geçiş biçimi değişirse aynı bildirimin tekrar çıkmasını
         * önler.
         */
        delete host.dataset.flash;
    }
}
