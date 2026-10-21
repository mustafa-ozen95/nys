import { $, on } from '../lib/dom.js';
import { emit } from './toast.js';

/**
 * Bildirim akışı: olan biten.
 *
 * Cihazda olan bir şey, onu başlatan kişinin değil AĞIN olayıdır — akış
 * herkese aynı içeriği gösterir. Sunucu hazır metin döndürür; burada karar
 * verilmez, yalnızca çizilir.
 */
const ARALIK_MS = 15000;
const ILK_GECIKME_MS = 800;

let sonGorulen = new Set();
let ilkTur = true;

function satir(kayit) {
    const el = document.createElement('a');

    el.className = `bildirim__satir${kayit.unseen ? ' bildirim__satir--yeni' : ''}`;
    el.href = kayit.switch_uuid ? `/switchler/${kayit.switch_uuid}` : '#';

    const ust = document.createElement('div');
    ust.className = 'bildirim__ust';

    const baslik = document.createElement('span');
    baslik.textContent = kayit.switch ? `${kayit.switch} · ${kayit.title}` : kayit.title;

    const rozet = document.createElement('span');
    rozet.className = `badge badge-${kayit.badge}`;
    rozet.textContent = kayit.label;

    ust.append(baslik, rozet);
    el.append(ust);

    if (kayit.detail) {
        const detay = document.createElement('p');
        detay.className = 'bildirim__detay';
        detay.textContent = kayit.detail;
        el.append(detay);
    }

    const alt = document.createElement('p');
    alt.className = 'bildirim__detay';
    alt.textContent = kayit.actor ? `${kayit.actor} · ${kayit.ago}` : kayit.ago;
    el.append(alt);

    return el;
}

function ciz(veri) {
    const liste = $('[data-bildirim-liste]');
    const sayac = $('[data-bildirim-sayac]');

    if (!liste || !sayac) {
        return;
    }

    liste.textContent = '';

    if (veri.items.length === 0) {
        const bos = document.createElement('p');
        bos.className = 'bildirim__bos';
        bos.textContent = 'Son 24 saatte bildirim yok.';
        liste.append(bos);
    } else {
        veri.items.forEach((kayit) => liste.append(satir(kayit)));
    }

    sayac.textContent = String(veri.unseen);
    sayac.hidden = veri.unseen === 0;
}

/**
 * YENİ gelen kayıt için kısa bildirim.
 *
 * İlk turda gösterilmez: sayfa açılışında son 24 saatin tamamı "yeni"
 * sayılıp ekran toast yağmuruna dönerdi.
 */
function duyur(veri) {
    const yeniler = veri.items.filter((kayit) => !sonGorulen.has(kayit.id));

    sonGorulen = new Set(veri.items.map((kayit) => kayit.id));

    if (ilkTur) {
        ilkTur = false;

        return;
    }

    yeniler.slice(0, 3).forEach((kayit) => {
        emit(
            kayit.switch ? `${kayit.switch} · ${kayit.title} — ${kayit.label}` : `${kayit.title} — ${kayit.label}`,
            kayit.kind === 'failure' ? 'danger' : 'success',
        );
    });
}

async function yokla() {
    try {
        const yanit = await fetch('/bildirimler', { headers: { Accept: 'application/json' } });

        if (!yanit.ok) {
            return;
        }

        const veri = await yanit.json();

        ciz(veri);
        duyur(veri);
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
async function okunduIsaretle() {
    /*
     * SONUÇ KONTROL EDİLİR. Yanıt bakılmadan yutulursa uç 422 dönerken ekran
     * hiçbir şey söylemez: gerekçe ara katmanı isteği reddeder, kullanıcı
     * yalnızca "işaretlenmedi" görür. fetch 4xx'te throw ETMEZ.
     */
    try {
        const yanit = await fetch('/bildirimler/okundu', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        });

        if (!yanit.ok) {
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
let yoklamaKuruldu = false;

export function init() {
    if (! $('[data-bildirim]')) {
        return;
    }

    /*
     * Bağlar DELEGE edilir. Üst çubuk her sayfa geçişinde yeniden çiziliyor
     * (bkz. navigation.js); düğümün kendisine bağlanan bir dinleyici ilk
     * geçişte kopardı.
     */
    on('click', '[data-bildirim-ac]', (olay, dugme) => {
        const panel = $('[data-bildirim-panel]');

        if (! panel) {
            return;
        }

        const acik = ! panel.hidden;

        panel.hidden = acik;
        dugme.setAttribute('aria-expanded', String(! acik));

        if (! acik) {
            yokla();
        }
    });

    on('click', '[data-bildirim-okundu]', async () => {
        await okunduIsaretle();
        yokla();
    });

    /*
     * "Tümü" bağlantısı da okundu sayar: kullanıcı listeye gidiyorsa
     * bildirimleri görmüş demektir. İşaret gönderilmeden sayfa değişmesin
     * diye varsayılan davranış beklenir.
     */
    on('click', '[data-bildirim-tumu]', async (olay, baglanti) => {
        /*
         * ADRES ÖNCE ALINIR: "await"ten sonra olay.currentTarget NULL olur
         * (tarayıcı işleyici dönünce onu sıfırlıyor) ve bağlantı hiçbir yere
         * gitmezdi. Bir kez tam olarak böyle oldu.
         */
        const adres = baglanti.href;

        olay.preventDefault();
        await okunduIsaretle();

        window.location.href = adres;
    });

    on('click', 'body', (olay) => {
        const kok = $('[data-bildirim]');
        const panel = $('[data-bildirim-panel]');

        if (kok && panel && ! panel.hidden && ! kok.contains(olay.target)) {
            panel.hidden = true;
            $('[data-bildirim-ac]')?.setAttribute('aria-expanded', 'false');
        }
    });

    if (yoklamaKuruldu) {
        return;
    }

    yoklamaKuruldu = true;

    setTimeout(yokla, ILK_GECIKME_MS);
    setInterval(yokla, ARALIK_MS);
}
