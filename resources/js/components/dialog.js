import { $, on } from '../lib/dom.js';

/**
 * Form pencerelerini açıp kapatır.
 *
 * Açmak için: <button data-modal-open="blok-ekle">
 * Kapatmak için: data-modal-close, Escape veya zemine tıklama.
 *
 * Sunucudan doğrulama hatasıyla dönen pencere data-modal-acik ile açık gelir.
 */

function open(id) {
    const box = $(`#${CSS.escape(id)}[data-modal]`);

    if (!box) {
        return;
    }

    box.hidden = false;
    box.querySelector('input:not([type=hidden]), select, textarea')?.focus();
}

/**
 * Pencere kapanınca İÇİ SIFIRLANIR.
 *
 * Kapatmak yalnızca gizleseydi yarım doldurulmuş bir form, bir daha
 * açıldığında olduğu gibi karşılardı. Kullanıcı ya eski metni siler ya da —
 * daha kötüsü — fark etmeden onu gönderir; düzenleme pencerelerinde bu,
 * başka bir kaydın değerlerini göndermek demektir.
 *
 * `form.reset()` boşaltmaz, SUNUCUNUN ÇİZDİĞİ hâle döndürür: düzenleme
 * penceresi kaydın kendi değerlerine geri gelir, ekleme penceresi boşalır.
 */
function close(box) {
    if (!box) {
        return;
    }

    box.hidden = true;

    box.querySelectorAll('form').forEach((form) => form.reset());

    box.querySelectorAll('.field__error').forEach((hata) => hata.remove());
    box.querySelectorAll('[aria-invalid="true"]').forEach((alan) => {
        alan.setAttribute('aria-invalid', 'false');
    });
}

export function init() {
    on('click', '[data-modal-open]', (event, trigger) => {
        event.preventDefault();
        open(trigger.dataset.modalOpen);
    });

    on('click', '[data-modal-close]', (event, trigger) => {
        event.preventDefault();
        close(trigger.closest('[data-modal]'));
    });

    // Zemine tıklamak kapatır; kutunun içine tıklamak kapatmaz.
    on('click', '[data-modal]', (event, box) => {
        if (event.target === box) {
            close(box);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close($('[data-modal]:not([hidden])'));
        }
    });

    $('[data-modal-acik]')?.removeAttribute('hidden');

    /*
     * DOĞRULAMA HATASI OLAN PENCERE KENDİLİĞİNDEN AÇILIR.
     *
     * Sunucu hatayı alanın altına yazar (bkz. form/field.blade.php); pencere
     * kapalı dönerse kullanıcı hiçbir şey görmez: form gönderilir, sayfa
     * yenilenir, pencere kapanır ve ekranda "bir şey olmadı" izlenimi
     * kalır — oysa hata oradadır, görünmez.
     *
     * Kural GENELDİR: hangi pencere olduğu Blade'de ayrıca işaretlenmez,
     * içinde hata olan pencere kendini açar. Yirmi forma tek tek gizli alan
     * eklemek, birini unutmaya açık olurdu.
     */
    const hatali = $('[data-modal] .field__error')?.closest('[data-modal]');

    if (hatali) {
        hatali.hidden = false;
        hatali.querySelector('.field__error')
            ?.closest('.field')
            ?.querySelector('input, select, textarea')
            ?.focus();
    }
}
