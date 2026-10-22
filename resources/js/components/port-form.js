import { $$, on } from '../lib/dom.js';

/**
 * Port ayarları formunda VLAN alanı port moduna bağlıdır.
 *
 * Cihazda access portta tek VLAN üyesi olabilir ("Access interface has more
 * than one vlan member" ile reddeder) ve o üye zaten etiketsizdir. Trunk
 * portta ise üyelik bir listedir ve etiketsiz VLAN ayrıca seçilir.
 *
 * Gizlenen alanın girdileri devre dışı bırakılır; aksi hâlde form her iki
 * biçimi de gönderir ve sunucu çelişkili bir istek alır.
 */
function apply(form) {
    const mode = form.querySelector('[data-port-mode]');

    if (! mode) {
        return;
    }

    form.querySelectorAll('[data-port-vlan]').forEach((field) => {
        const visible = field.dataset.portVlan === mode.value;

        field.hidden = ! visible;
        field.querySelectorAll('select, input').forEach((input) => {
            input.disabled = ! visible;
        });
    });
}

/**
 * Half-duplex yalnızca 10/100 Mbps ile geçerlidir.
 *
 * Cihaz aksini reddediyor: "Half duplex is an invalid configuration when
 * speed is 10G/1G/Auto". Seçenek burada kapatılır; aynı kısıt sunucuda da
 * durur (SwitchPortRequest) — arayüz yalnızca kullanıcıyı boşuna
 * uğraştırmasın diye önceden söyler.
 */
function duplexConstraint(form) {
    const duplex = form?.querySelector('[data-port-duplex]');
    const speed = form?.querySelector('[data-port-speed]');

    if (! duplex || ! speed) {
        return;
    }

    const permitted = (duplex.dataset.portHalfSpeeds ?? '').split(',').filter(Boolean);
    const open = permitted.includes(speed.value);
    const half = duplex.querySelector('option[value="half"]');

    if (! half) {
        return;
    }

    half.disabled = ! open;

    // Kapanan seçenek seçiliyse otomatiğe düşülür; gönderilse sunucu reddederdi.
    if (! open && duplex.value === 'half') {
        duplex.value = '';
    }
}

export function init() {
    $$('[data-port-mode]').forEach((selector) => apply(selector.closest('form')));
    $$('[data-port-duplex]').forEach((selector) => duplexConstraint(selector.closest('form')));

    on('change', '[data-port-mode]', (event, selector) => {
        apply(selector.closest('form'));
    });

    on('change', '[data-port-speed]', (event, selector) => {
        duplexConstraint(selector.closest('form'));
    });
}

/**
 * Switch formunda NETCONF kapalıysa kimlik alanları gizlenir; kapalı bir
 * switch panelden yapılandırılamaz, alanları sormanın anlamı yoktur.
 */
function netconfFields(form) {
    const key = form.querySelector('[data-netconf-toggle]');
    const field = form.querySelector('[data-netconf-field]');

    if (! key || ! field) {
        return;
    }

    field.hidden = ! key.checked;
}

export function initNetconf() {
    $$('[data-netconf-toggle]').forEach((key) => netconfFields(key.closest('form')));

    on('change', '[data-netconf-toggle]', (event, key) => {
        netconfFields(key.closest('form'));
    });
}
