import { on } from '../lib/dom.js';

/**
 * Statik route penceresi.
 *
 * İki iş yapar:
 *
 * 1. Yolun ne yapacağı seçilince atlama alanları görünür/gizlenir. "Discard",
 *    "reject" ve "receive" sonraki atlama almaz; alan açık kalırsa kullanıcı
 *    doldurur ve sunucu "bu alan yasak" der.
 * 2. Tekrarlanan alanlar (atlamalar, yedek atlamalar, community) eklenip
 *    çıkarılabilir. ECMP tam olarak budur: aynı route'a birden çok atlama.
 *
 * Kurallar sunucuda da vardır (SwitchRouteRequest); buradaki yalnızca
 * görünürlük ve kolaylıktır.
 */
function eylemiUygula(select) {
    const pencere = select.closest('.modal, form');
    const alan = pencere?.querySelector('[data-route-hop]');

    if (alan) {
        alan.classList.toggle('is-hidden', select.value !== 'next-hop');
    }
}

/**
 * Satırı kopyalayıp temizler. Dizi adları ("next_hops[]") olduğu gibi kalır;
 * indeksli adlar (qualified_next_hops[0][name]) yeniden numaralanır.
 */
function satirEkle(button) {
    const kap = button.closest('[data-repeat]');
    const sonSatir = kap?.querySelector('[data-repeat-row]:last-of-type');

    if (! kap || ! sonSatir) {
        return;
    }

    const yeni = sonSatir.cloneNode(true);

    yeni.querySelectorAll('input, select').forEach((girdi) => {
        girdi.value = '';
    });

    kap.insertBefore(yeni, button);
    yenidenNumarala(kap);
}

function satirCikar(button) {
    const kap = button.closest('[data-repeat]');
    const satir = button.closest('[data-repeat-row]');

    // Son satır silinmez; boş bırakmak yeterli.
    if (kap && satir && kap.querySelectorAll('[data-repeat-row]').length > 1) {
        satir.remove();
        yenidenNumarala(kap);
    } else if (satir) {
        satir.querySelectorAll('input, select').forEach((girdi) => {
            girdi.value = '';
        });
    }
}

function yenidenNumarala(kap) {
    kap.querySelectorAll('[data-repeat-row]').forEach((satir, index) => {
        satir.querySelectorAll('[name]').forEach((girdi) => {
            girdi.name = girdi.name.replace(/\[\d+\]/, `[${index}]`);
        });
    });
}

export function init() {
    document.querySelectorAll('[data-route-action]').forEach(eylemiUygula);

    on('change', '[data-route-action]', (event, select) => eylemiUygula(select));
    on('click', '[data-repeat-add]', (event, button) => satirEkle(button));
    on('click', '[data-repeat-remove]', (event, button) => satirCikar(button));
}
