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
function uygula(form) {
    const mod = form.querySelector('[data-port-mode]');

    if (! mod) {
        return;
    }

    form.querySelectorAll('[data-port-vlan]').forEach((alan) => {
        const gorunur = alan.dataset.portVlan === mod.value;

        alan.hidden = ! gorunur;
        alan.querySelectorAll('select, input').forEach((girdi) => {
            girdi.disabled = ! gorunur;
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
function duplexKisiti(form) {
    const duplex = form?.querySelector('[data-port-duplex]');
    const hiz = form?.querySelector('[data-port-speed]');

    if (! duplex || ! hiz) {
        return;
    }

    const izinli = (duplex.dataset.portHalfSpeeds ?? '').split(',').filter(Boolean);
    const acik = izinli.includes(hiz.value);
    const half = duplex.querySelector('option[value="half"]');

    if (! half) {
        return;
    }

    half.disabled = ! acik;

    // Kapanan seçenek seçiliyse otomatiğe düşülür; gönderilse sunucu reddederdi.
    if (! acik && duplex.value === 'half') {
        duplex.value = '';
    }
}

export function init() {
    $$('[data-port-mode]').forEach((secici) => uygula(secici.closest('form')));
    $$('[data-port-duplex]').forEach((secici) => duplexKisiti(secici.closest('form')));

    on('change', '[data-port-mode]', (event, secici) => {
        uygula(secici.closest('form'));
    });

    on('change', '[data-port-speed]', (event, secici) => {
        duplexKisiti(secici.closest('form'));
    });
}

/**
 * Switch formunda NETCONF kapalıysa kimlik alanları gizlenir; kapalı bir
 * switch panelden yapılandırılamaz, alanları sormanın anlamı yoktur.
 */
function netconfAlanlari(form) {
    const anahtar = form.querySelector('[data-netconf-toggle]');
    const alan = form.querySelector('[data-netconf-alan]');

    if (! anahtar || ! alan) {
        return;
    }

    alan.hidden = ! anahtar.checked;
}

export function initNetconf() {
    $$('[data-netconf-toggle]').forEach((anahtar) => netconfAlanlari(anahtar.closest('form')));

    on('change', '[data-netconf-toggle]', (event, anahtar) => {
        netconfAlanlari(anahtar.closest('form'));
    });
}
