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

function ordered(list) {
    return $$('[data-order-id]', list).map((row) => row.dataset.orderId);
}

/**
 * Sürüklenen satırın imlece göre nereye bırakılacağını bulur.
 */
function targetRow(list, y) {
    return $$('[data-order-id]:not(.is-dragging)', list).reduce(
        (nearest, row) => {
            const box = row.getBoundingClientRect();
            const diff = y - box.top - box.height / 2;

            return diff < 0 && diff > nearest.fark ? { fark: diff, satir: row } : nearest;
        },
        { fark: Number.NEGATIVE_INFINITY, satir: null },
    ).satir;
}

async function send(list) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = list.dataset.order;
    form.hidden = true;

    // Sunucuya yazma isteği tek yerden geçsin diye normal form gönderimi
    // kullanılır: onay penceresi, hata gösterimi ve yönlendirme aynı kalır.
    const fields = [
        ['_token', document.querySelector('meta[name="csrf-token"]')?.content ?? ''],
        ['_method', 'PUT'],
        ...ordered(list).map((kimlik) => ['terms[]', kimlik]),
    ];

    fields.forEach(([name, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
}

export function init() {
    let dragged = null;

    on('dragstart', '[data-order-id]', (event, row) => {
        dragged = row;
        row.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
    });

    on('dragend', '[data-order-id]', (event, row) => {
        row.classList.remove('is-dragging');
    });

    on('dragover', '[data-order]', (event, list) => {
        if (!dragged || !list.contains(dragged)) {
            return;
        }

        // Varsayılan davranış bırakmayı engeller.
        event.preventDefault();

        const target = targetRow(list, event.clientY);

        if (target) {
            list.insertBefore(dragged, target);
        } else {
            list.appendChild(dragged);
        }
    });

    on('drop', '[data-order]', (event, list) => {
        if (!dragged || !list.contains(dragged)) {
            return;
        }

        event.preventDefault();
        dragged = null;

        send(list).catch(() => {});
    });
}
