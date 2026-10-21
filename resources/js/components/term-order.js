import { $$, on } from '../lib/dom.js';

/**
 * Terimleri sürükleyerek sıralar.
 *
 * Sıra bir görsel tercih değil: Junos'ta İLK EŞLEŞEN terim kazanır ve
 * arkasındakiler hiç çalışmaz. Bu yüzden bırakma anında yeni sıra sunucuya
 * gönderilir ve cihaza yazılır — tarayıcıda görünen sıra ile cihazdaki sıra
 * ayrılamaz.
 *
 * Dış kütüphane kullanılmaz; tarayıcının kendi sürükleme olayları yeterli.
 */

function sirali(liste) {
    return $$('[data-sira-kimlik]', liste).map((satir) => satir.dataset.siraKimlik);
}

/**
 * Sürüklenen satırın imlece göre nereye bırakılacağını bulur.
 */
function hedefSatir(liste, y) {
    return $$('[data-sira-kimlik]:not(.is-dragging)', liste).reduce(
        (yakin, satir) => {
            const kutu = satir.getBoundingClientRect();
            const fark = y - kutu.top - kutu.height / 2;

            return fark < 0 && fark > yakin.fark ? { fark, satir } : yakin;
        },
        { fark: Number.NEGATIVE_INFINITY, satir: null },
    ).satir;
}

async function gonder(liste) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = liste.dataset.sira;
    form.hidden = true;

    // Sunucuya yazma isteği tek yerden geçsin diye normal form gönderimi
    // kullanılır: onay penceresi, hata gösterimi ve yönlendirme aynı kalır.
    const alanlar = [
        ['_token', document.querySelector('meta[name="csrf-token"]')?.content ?? ''],
        ['_method', 'PUT'],
        ...sirali(liste).map((kimlik) => ['terms[]', kimlik]),
    ];

    alanlar.forEach(([ad, deger]) => {
        const girdi = document.createElement('input');
        girdi.type = 'hidden';
        girdi.name = ad;
        girdi.value = deger;
        form.appendChild(girdi);
    });

    document.body.appendChild(form);
    form.submit();
}

export function init() {
    let suruklenen = null;

    on('dragstart', '[data-sira-kimlik]', (event, satir) => {
        suruklenen = satir;
        satir.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
    });

    on('dragend', '[data-sira-kimlik]', (event, satir) => {
        satir.classList.remove('is-dragging');
    });

    on('dragover', '[data-sira]', (event, liste) => {
        if (!suruklenen || !liste.contains(suruklenen)) {
            return;
        }

        // Varsayılan davranış bırakmayı engeller.
        event.preventDefault();

        const hedef = hedefSatir(liste, event.clientY);

        if (hedef) {
            liste.insertBefore(suruklenen, hedef);
        } else {
            liste.appendChild(suruklenen);
        }
    });

    on('drop', '[data-sira]', (event, liste) => {
        if (!suruklenen || !liste.contains(suruklenen)) {
            return;
        }

        event.preventDefault();
        suruklenen = null;

        gonder(liste).catch(() => {});
    });
}
