import { $, $$ } from '../lib/dom.js';
import { refresh } from './navigation.js';

/**
 * Süren parola turlarının ilerleme çubukları.
 *
 * NEDEN VAR: tur kuyruğa alınır ve bir switchte dört oturum ile üç commit
 * sürer. Toplu turda onlarca cihaz aynı anda sırada bekler; çubuk olmasa
 * ekran o süre boyunca yalnızca "kuyruğa alındı" der ve hangi cihazın nerede
 * olduğu ancak sayfa yenilenince görülürdü.
 *
 * TEK YOKLAMA, BÜTÜN SATIRLAR. Her satırın kendi isteğini atması sayfayı
 * saniyede onlarca isteğe boğardı; uç bütün cihazları tek yanıtta veriyor.
 *
 * Çubuk KARAR VERMEZ: aşamayı sunucudan okur. Tahmin eden bir çubuk, tur
 * takıldığında dolmaya devam ederek yalan söylerdi.
 */

/* Yoklama aralığı (ms). Tur dakikalar sürüyor; daha sık yoklamak boşuna yük. */
const INTERVAL = 2000;

/*
 * Yoklama üst sınırı. Kuyruk durmuş ya da iş ölmüşse sayfa sonsuza dek
 * sunucuya soru sormamalı.
 */
const MAX = 300;

let timer = null;

function stop() {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
}

function draw(root, data) {
    const bar = root.querySelector('[data-progress-bar]');
    const text = root.querySelector('[data-progress-text]');

    if (bar) {
        bar.style.width = `${data.yuzde}%`;
        bar.parentElement?.setAttribute('aria-valuenow', String(data.yuzde));
    }

    if (text) {
        text.textContent = data.adim > 0
            ? `${data.adim}/${data.toplam} · ${data.metin}`
            : data.metin;
    }
}

/**
 * Sayfanın başındaki özet şerit.
 *
 * Toplam doluluk ADIM SAYISINDAN hesaplanır (biten adımlar / toplam adımlar),
 * yüzdelerin ortalamasından değil: iki cihazdan biri bitmiş diğeri
 * başlamışken "ortalama" ikisinin de yarısında olduğunu söylerdi.
 */
function drawSummary(devices) {
    const root = $('[data-progress-summary]');

    if (!root) {
        return;
    }

    const statuses = Object.values(devices);

    if (statuses.length === 0) {
        return;
    }

    const finished = statuses.reduce((t, d) => t + (d.adim ?? 0), 0);
    const total = Math.max(1, statuses.reduce((t, d) => t + (d.toplam ?? 0), 0));
    const percent = Math.round((finished / total) * 100);

    const bar = root.querySelector('[data-progress-summary-bar]');
    const text = root.querySelector('[data-progress-summary-text]');
    const step = root.querySelector('[data-progress-summary-step]');

    if (bar) {
        bar.style.width = `${percent}%`;
        bar.parentElement?.setAttribute('aria-valuenow', String(percent));
    }

    if (text) {
        text.textContent = `${statuses.length} cihazda parola turu sürüyor`;
    }

    if (step) {
        step.textContent = `${finished}/${total} adım`;
    }
}

async function poll(address, remaining) {
    if (remaining <= 0) {
        stop();

        return;
    }

    let data;

    try {
        const response = await fetch(address, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        data = await response.json();
    } catch {
        /*
         * Tek bir başarısız yoklama turu bitirmez: ağ kesintisi cihazdaki işi
         * durdurmuyor. Sayaç işliyor, üst sınıra gelince duruyoruz.
         */
        timer = setTimeout(() => poll(address, remaining - 1), INTERVAL);

        return;
    }

    const devices = data.cihazlar ?? {};

    $$('[data-progress-device]').forEach((root) => {
        const status = devices[root.dataset.progressDevice];

        if (status) {
            draw(root, status);
        }
    });

    drawSummary(devices);

    if (data.calisiyor === false) {
        /*
         * Bütün turlar bitti. Sonuç rozetleri, yeni parolalar ve yarıda
         * kalmış tur uyarısı sunucuda çiziliyor; burada taklit edilmez,
         * sayfa YERİNDE tazelenir (sayfa geçişlerindeki gibi). Tarayıcı
         * yenilemesi ekranı beyaza düşürür ve kullanıcıyı baktığı yerden
         * koparırdı.
         */
        stop();
        refresh();

        return;
    }

    timer = setTimeout(() => poll(address, remaining - 1), INTERVAL);
}

/*
 * SUNUCUDAN GELEN İLK DEĞERİ UYGULA.
 *
 * Çubuğun başlangıç genişliği Blade'de `style="width: X%"` olarak
 * basılamaz: CSP satır içi stil özniteliklerini engellediği için HİÇ
 * UYGULANMAZ; sayfa açıldığında her çubuk boş görünür ve ilk yoklama dönene
 * kadar öyle kalır. Üstelik sessizce: konsolda bir şey görünmez.
 *
 * Buradan yazmak CSP'ye takılmaz; CSSOM üzerinden yapılan değişiklik satır
 * içi stil sayılmaz. Değeri yine sunucu söylüyor — çubuk hâlâ karar vermiyor.
 */
function applyDefaults() {
    $$('[data-start]').forEach((bar) => {
        const percent = Number(bar.dataset.start);

        if (Number.isFinite(percent)) {
            bar.style.width = `${percent}%`;
        }
    });
}

export function init() {
    stop();
    applyDefaults();

    const root = $('[data-progress-address]');

    if (!root) {
        return;
    }

    timer = setTimeout(() => poll(root.dataset.progressAddress, MAX), INTERVAL);
}
