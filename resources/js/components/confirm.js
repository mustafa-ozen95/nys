import { $, on } from '../lib/dom.js';
import { submitForm} from './navigation.js';

/**
 * Tehlikeli işlemler için onay penceresi.
 *
 * Kullanımı — herhangi bir form veya bağlantıya öznitelik eklemek yeterlidir:
 *   data-confirm="Bu switch silinecek."
 *   data-confirm-title="Switchi sil"
 *   data-confirm-variant="danger"
 *   data-confirm-verify="sw-test-1"   (kullanıcının yazarak doğrulaması istenir)
 *   data-confirm-reasonless      (gerekçe istenmez — yalnızca okuyan işlemler)
 *
 * Gerekçe varsayılan olarak ZORUNLUDUR ve forma "reason" alanı olarak
 * eklenir; sunucu tarafında da aranır, yani pencereyi atlamak işe yaramaz.
 */

let pending = null;

function dialog() {
    return $('[data-confirm-dialog]');
}

function open(trigger) {
    const box = dialog();

    if (!box) {
        return false;
    }

    pending = trigger;

    box.querySelector('[data-confirm-title]').textContent =
        trigger.dataset.confirmTitle || 'Onay gerekiyor';
    box.querySelector('[data-confirm-message]').textContent = trigger.dataset.confirm;

    const verify = trigger.dataset.confirmVerify ?? '';
    const verifyField = box.querySelector('[data-confirm-verify-field]');
    const input = box.querySelector('[data-confirm-input]');
    const accept = box.querySelector('[data-confirm-accept]');
    const reasonField = box.querySelector('[data-confirm-reason-field]');
    const reason = box.querySelector('[data-confirm-reason]');

    // Gerekçe yalnızca durumu değiştirmeyen işlemlerde istenmez.
    const reasonless = trigger.dataset.confirmReasonless !== undefined;

    verifyField.hidden = verify === '';
    reasonField.hidden = reasonless;
    box.querySelector('[data-confirm-expected]').textContent = verify;
    input.value = '';
    reason.value = '';

    accept.className = `btn btn--${trigger.dataset.confirmVariant === 'danger' ? 'danger' : 'primary'}`;

    const refresh = () => {
        const verified = verify === '' || input.value.trim() === verify;
        const withReason = reasonless || reason.value.trim().length >= 3;

        accept.disabled = !verified || !withReason;
    };

    input.oninput = refresh;
    reason.oninput = refresh;
    refresh();

    box.hidden = false;
    (reasonless ? (verify === '' ? accept : input) : reason).focus();

    return true;
}

function close() {
    const box = dialog();

    if (box) {
        box.hidden = true;
    }

    pending = null;
}

function proceed() {
    const trigger = pending;
    const justification = dialog()?.querySelector('[data-confirm-reason]')?.value?.trim() ?? '';

    close();

    if (!trigger) {
        return;
    }

    // İkinci turda onay istenmesin diye öznitelik kaldırılır.
    delete trigger.dataset.confirm;

    if (trigger.tagName === 'FORM') {
        // Gerekçe forma taşınır; sunucu onu arar.
        if (justification !== '') {
            const field = document.createElement('input');

            field.type = 'hidden';
            field.name = 'reason';
            field.value = justification;
            trigger.appendChild(field);
        }

        /*
         * Uzun süren işi başlatan form YERİNDE gönderilir; tam sayfa
         * yenilemesi ekranı beyaza düşürür.
         */
        if (trigger.dataset.inPlace !== undefined) {
            submitForm(trigger);

            return;
        }

        trigger.submit();

        return;
    }

    trigger.click();
}

export function init() {
    on('submit', 'form[data-confirm]', (event, form) => {
        if (open(form)) {
            event.preventDefault();
        }
    });

    on('click', 'a[data-confirm], button[data-confirm]', (event, element) => {
        if (open(element)) {
            event.preventDefault();
        }
    });

    /* Onay istemeyen ama yerinde gönderilmesi gereken formlar. */
    on('submit', 'form[data-in-place]:not([data-confirm])', (event, form) => {
        event.preventDefault();
        submitForm(form);
    });

    on('click', '[data-confirm-accept]', proceed);
    on('click', '[data-confirm-cancel]', close);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !dialog()?.hidden) {
            close();
        }
    });
}
