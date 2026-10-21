import { $, $$ } from '../lib/dom.js';
import { tazele } from './navigation.js';

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
const ARALIK = 2000;

/*
 * Yoklama üst sınırı. Kuyruk durmuş ya da iş ölmüşse sayfa sonsuza dek
 * sunucuya soru sormamalı.
 */
const AZAMI = 300;

let zamanlayici = null;

function durdur() {
    if (zamanlayici !== null) {
        clearTimeout(zamanlayici);
        zamanlayici = null;
    }
}

function ciz(kok, veri) {
    const cubuk = kok.querySelector('[data-ilerleme-cubuk]');
    const metin = kok.querySelector('[data-ilerleme-metin]');

    if (cubuk) {
        cubuk.style.width = `${veri.yuzde}%`;
        cubuk.parentElement?.setAttribute('aria-valuenow', String(veri.yuzde));
    }

    if (metin) {
        metin.textContent = veri.adim > 0
            ? `${veri.adim}/${veri.toplam} · ${veri.metin}`
            : veri.metin;
    }
}

/**
 * Sayfanın başındaki özet şerit.
 *
 * Toplam doluluk ADIM SAYISINDAN hesaplanır (biten adımlar / toplam adımlar),
 * yüzdelerin ortalamasından değil: iki cihazdan biri bitmiş diğeri
 * başlamışken "ortalama" ikisinin de yarısında olduğunu söylerdi.
 */
function ozetiCiz(cihazlar) {
    const kok = $('[data-ilerleme-ozet]');

    if (!kok) {
        return;
    }

    const durumlar = Object.values(cihazlar);

    if (durumlar.length === 0) {
        return;
    }

    const biten = durumlar.reduce((t, d) => t + (d.adim ?? 0), 0);
    const toplam = Math.max(1, durumlar.reduce((t, d) => t + (d.toplam ?? 0), 0));
    const yuzde = Math.round((biten / toplam) * 100);

    const cubuk = kok.querySelector('[data-ilerleme-ozet-cubuk]');
    const metin = kok.querySelector('[data-ilerleme-ozet-metin]');
    const adim = kok.querySelector('[data-ilerleme-ozet-adim]');

    if (cubuk) {
        cubuk.style.width = `${yuzde}%`;
        cubuk.parentElement?.setAttribute('aria-valuenow', String(yuzde));
    }

    if (metin) {
        metin.textContent = `${durumlar.length} cihazda parola turu sürüyor`;
    }

    if (adim) {
        adim.textContent = `${biten}/${toplam} adım`;
    }
}

async function yokla(adres, kalan) {
    if (kalan <= 0) {
        durdur();

        return;
    }

    let veri;

    try {
        const yanit = await fetch(adres, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!yanit.ok) {
            throw new Error(String(yanit.status));
        }

        veri = await yanit.json();
    } catch {
        /*
         * Tek bir başarısız yoklama turu bitirmez: ağ kesintisi cihazdaki işi
         * durdurmuyor. Sayaç işliyor, üst sınıra gelince duruyoruz.
         */
        zamanlayici = setTimeout(() => yokla(adres, kalan - 1), ARALIK);

        return;
    }

    const cihazlar = veri.cihazlar ?? {};

    $$('[data-ilerleme-cihaz]').forEach((kok) => {
        const durum = cihazlar[kok.dataset.ilerlemeCihaz];

        if (durum) {
            ciz(kok, durum);
        }
    });

    ozetiCiz(cihazlar);

    if (veri.calisiyor === false) {
        /*
         * Bütün turlar bitti. Sonuç rozetleri, yeni parolalar ve yarıda
         * kalmış tur uyarısı sunucuda çiziliyor; burada taklit edilmez,
         * sayfa YERİNDE tazelenir (sayfa geçişlerindeki gibi). Tarayıcı
         * yenilemesi ekranı beyaza düşürür ve kullanıcıyı baktığı yerden
         * koparırdı.
         */
        durdur();
        tazele();

        return;
    }

    zamanlayici = setTimeout(() => yokla(adres, kalan - 1), ARALIK);
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
function baslangiclariUygula() {
    $$('[data-baslangic]').forEach((cubuk) => {
        const yuzde = Number(cubuk.dataset.baslangic);

        if (Number.isFinite(yuzde)) {
            cubuk.style.width = `${yuzde}%`;
        }
    });
}

export function init() {
    durdur();
    baslangiclariUygula();

    const kok = $('[data-ilerleme-adres]');

    if (!kok) {
        return;
    }

    zamanlayici = setTimeout(() => yokla(kok.dataset.ilerlemeAdres, AZAMI), ARALIK);
}
