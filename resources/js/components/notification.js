import { $, on } from '../lib/dom.js';
import { emit } from './toast.js';

/**
 * Bildirim akışı: olan biten.
 *
 * Cihazda olan bir şey, onu başlatan kişinin değil AĞIN olayıdır — akış
 * herkese aynı içeriği gösterir. Sunucu hazır metin döndürür; burada karar
 * verilmez, yalnızca çizilir.
 */
const INTERVAL_MS = 15000;
const FIRST_DELAY_MS = 800;

let lastSeen = new Set();
let firstType = true;

function row(record) {
    const el = document.createElement('a');

    el.className = `notification__row${record.unseen ? ' notification__row--new' : ''}`;
    el.href = record.switch_uuid ? `/switches/${record.switch_uuid}` : '#';

    const top = document.createElement('div');
    top.className = 'notification__top';

    const header = document.createElement('span');
    header.textContent = record.switch ? `${record.switch} · ${record.title}` : record.title;

    const badge = document.createElement('span');
    badge.className = `badge badge-${record.badge}`;
    badge.textContent = record.label;

    top.append(header, badge);
    el.append(top);

    if (record.detail) {
        const detail = document.createElement('p');
        detail.className = 'notification__detail';
        detail.textContent = record.detail;
        el.append(detail);
    }

    const alt = document.createElement('p');
    alt.className = 'notification__detail';
    alt.textContent = record.actor ? `${record.actor} · ${record.ago}` : record.ago;
    el.append(alt);

    return el;
}

function draw(data) {
    const list = $('[data-notification-list]');
    const counter = $('[data-notification-counter]');

    if (!list || !counter) {
        return;
    }

    list.textContent = '';

    if (data.items.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'notification__empty';
        empty.textContent = 'Son 24 saatte bildirim yok.';
        list.append(empty);
    } else {
        data.items.forEach((record) => list.append(row(record)));
    }

    counter.textContent = String(data.unseen);
    counter.hidden = data.unseen === 0;
}

/**
 * YENİ gelen kayıt için kısa bildirim.
 *
 * İlk turda gösterilmez: sayfa açılışında son 24 saatin tamamı "yeni"
 * sayılıp ekran toast yağmuruna dönerdi.
 */
function announce(data) {
    const fresh = data.items.filter((record) => !lastSeen.has(record.id));

    lastSeen = new Set(data.items.map((record) => record.id));

    if (firstType) {
        firstType = false;

        return;
    }

    fresh.slice(0, 3).forEach((record) => {
        emit(
            record.switch ? `${record.switch} · ${record.title} — ${record.label}` : `${record.title} — ${record.label}`,
            record.kind === 'failure' ? 'danger' : 'success',
        );
    });
}

async function poll() {
    try {
        const response = await fetch('/notifications', { headers: { Accept: 'application/json' } });

        if (!response.ok) {
            return;
        }

        const data = await response.json();

        draw(data);
        announce(data);
    } catch {
        // Geçici bir ağ hatası akışı bitirmesin; bir sonraki tur dener.
    }
}

/**
 * Okundu işaretler.
 *
 * AÇIK BİR EYLEMDİR: paneli açmak tek başına işaretlemez. Kullanıcı bakmadan
 * açıp kapatabilir ve sayacı kaybetmek, görülmemiş bir hatayı kaybetmektir.
 * İşaret yalnızca bu kullanıcı için düşer.
 */
async function markRead() {
    /*
     * SONUÇ KONTROL EDİLİR. Yanıt bakılmadan yutulursa uç 422 dönerken ekran
     * hiçbir şey söylemez: gerekçe ara katmanı isteği reddeder, kullanıcı
     * yalnızca "işaretlenmedi" görür. fetch 4xx'te throw ETMEZ.
     */
    try {
        const response = await fetch('/notifications/seen', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        });

        if (!response.ok) {
            emit('Bildirimler okundu işaretlenemedi.', 'danger');

            return false;
        }

        return true;
    } catch {
        emit('Bildirimler okundu işaretlenemedi.', 'danger');

        return false;
    }
}

/** Yoklama bir kez kurulur; init her sayfa geçişinde koşuyor. */
let pollingStarted = false;

export function init() {
    if (! $('[data-notification]')) {
        return;
    }

    /*
     * Bağlar DELEGE edilir. Üst çubuk her sayfa geçişinde yeniden çiziliyor
     * (bkz. navigation.js); düğümün kendisine bağlanan bir dinleyici ilk
     * geçişte kopardı.
     */
    on('click', '[data-notification-open]', (event, button) => {
        const panel = $('[data-notification-panel]');

        if (! panel) {
            return;
        }

        const open = ! panel.hidden;

        panel.hidden = open;
        button.setAttribute('aria-expanded', String(! open));

        if (! open) {
            poll();
        }
    });

    on('click', '[data-notification-read]', async () => {
        await markRead();
        poll();
    });

    /*
     * "Tümü" bağlantısı da okundu sayar: kullanıcı listeye gidiyorsa
     * bildirimleri görmüş demektir. İşaret gönderilmeden sayfa değişmesin
     * diye varsayılan davranış beklenir.
     */
    on('click', '[data-notification-all]', async (event, connection) => {
        /*
         * ADRES ÖNCE ALINIR: "await"ten sonra olay.currentTarget NULL olur
         * (tarayıcı işleyici dönünce onu sıfırlıyor) ve bağlantı hiçbir yere
         * gitmezdi. Bir kez tam olarak böyle oldu.
         */
        const address = connection.href;

        event.preventDefault();
        await markRead();

        window.location.href = address;
    });

    on('click', 'body', (event) => {
        const root = $('[data-notification]');
        const panel = $('[data-notification-panel]');

        if (root && panel && ! panel.hidden && ! root.contains(event.target)) {
            panel.hidden = true;
            $('[data-notification-open]')?.setAttribute('aria-expanded', 'false');
        }
    });

    if (pollingStarted) {
        return;
    }

    pollingStarted = true;

    setTimeout(poll, FIRST_DELAY_MS);
    setInterval(poll, INTERVAL_MS);
}
