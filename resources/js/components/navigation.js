import { on } from '../lib/dom.js';

/**
 * Sayfa geçişleri — tarayıcı yenilemeden.
 *
 * Sunucu yine tam sayfa üretir; değişen tek şey, tarayıcının o sayfayı
 * baştan çizmek yerine gövdeyi yerinde değiştirmesi. Böylece tema, yazı
 * tipleri ve betikler yeniden yüklenmez, ekran beyaza düşmez.
 *
 * NEDEN KÜTÜPHANE DEĞİL: bileşenlerin neredeyse tamamı olayları zaten
 * document üzerinde dinliyor (bkz. lib/dom.js `on`), yani gövde değişince
 * kendiliğinden çalışmaya devam ediyorlar. Geriye kalan iş bir istek, bir
 * DOM değişimi ve geçmiş kaydı.
 *
 * SUNUCU TARAFINDA HİÇBİR ŞEY DEĞİŞMEZ. Yetki, doğrulama ve yönlendirme
 * aynı yerlerde; bu dosya yalnızca gösterimle ilgilenir. İstek başarısız
 * olursa ya da HTML dönmezse tarayıcının kendi gezinmesine düşülür — yani
 * en kötü durumda eski davranış.
 *
 * POST FORMLARI DOKUNULMAZ. Onlar onay penceresinden geçiyor, gerekçe
 * topluyor ve yönlendirmeyle bitiyor; araya girmek kazanılan şeye değmez.
 */

export const OLAY = 'nsm:sayfa';

/** Devam eden istek: hızlı tıklamada eskisi iptal edilir. */
let istek = null;

function ayniKoken(adres) {
    return adres.origin === window.location.origin;
}

/** Bu bağlantı bizim işimiz mi? */
function devralinir(baglanti) {
    const href = baglanti.getAttribute('href') ?? '';

    return href !== ''
        && ! href.startsWith('#')
        && ! baglanti.target
        && ! baglanti.hasAttribute('download')
        && baglanti.dataset.tamYenile === undefined
        && ayniKoken(new URL(baglanti.href, window.location.href));
}

function ciz(metin, adres, gecmis) {
    const yeni = new DOMParser().parseFromString(metin, 'text/html');

    document.title = yeni.title;

    /*
     * <head> yerinde kalır (biçem ve betikler yeniden yüklenmesin diye) ama
     * CSRF simgesi ORADADIR: oturum tazelenirse eski simge DOM'da kalır ve
     * AJAX istekleri 419 alırdı. Sunucunun yeni sayfada verdiği simge alınır.
     */
    const simge = yeni.querySelector('meta[name="csrf-token"]')?.content;
    const mevcut = document.querySelector('meta[name="csrf-token"]');

    if (simge && mevcut) {
        mevcut.content = simge;
    }

    /*
     * Tema kök öğede duruyor (data-theme) ve <head> ile birlikte yerinde
     * kalır — sunucunun döndürdüğü değerle ezilmez, kullanıcının seçimi
     * geçişte kaybolmasın.
     */
    document.body.innerHTML = yeni.body.innerHTML;

    if (gecmis === 'ekle') {
        window.history.pushState({ nsm: true }, '', adres);
        window.scrollTo(0, 0);
    }

    /* Düğüme bağlı çalışan bileşenler kendilerini burada tazeler. */
    document.dispatchEvent(new CustomEvent(OLAY));
}

async function git(adres, gecmis = 'ekle') {
    istek?.abort();
    istek = new AbortController();

    document.body.dataset.gecis = '';

    try {
        const yanit = await fetch(adres, {
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
            signal: istek.signal,
        });

        /*
         * HTML değilse (dosya indirme, beklenmedik bir yanıt) araya girmeyiz:
         * tarayıcı kendi bildiğini yapsın.
         */
        if (! yanit.ok || ! (yanit.headers.get('content-type') ?? '').includes('text/html')) {
            window.location.href = adres;

            return;
        }

        /* Yönlendirme olduysa adres çubuğunda VARILAN yer yazmalı. */
        ciz(await yanit.text(), yanit.url, gecmis);
    } catch (hata) {
        if (hata.name !== 'AbortError') {
            window.location.href = adres;
        }
    } finally {
        delete document.body.dataset.gecis;
    }
}

/**
 * Formu YERİNDE gönderir: sayfa yenilenmez.
 *
 * POST formlarına genel olarak dokunulmuyor ama uzun süren bir işi BAŞLATAN
 * form istisnadır: tam sayfa yenilemesiyle ekran beyaza düşer ve kullanıcı
 * işin başladığını ancak yeni sayfa çizilince görebilir — tam da ilerlemeyi
 * izlemek istediği anda.
 *
 * Sunucu tarafında hiçbir şey değişmez: aynı uç, aynı yönlendirme, aynı
 * bildirim. Yalnızca dönen sayfa tarayıcıyı yenilemek yerine gövdeye
 * çizilir.
 *
 * İstek başarısız olursa ya da HTML dönmezse formun KENDİ gönderimine
 * düşülür — en kötü durumda eski davranış.
 */
export async function formuGonder(form) {
    const adres = form.getAttribute('action') || window.location.href;

    document.body.dataset.gecis = '';

    try {
        const yanit = await fetch(adres, {
            method: (form.getAttribute('method') || 'post').toUpperCase(),
            body: new FormData(form),
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
        });

        if (! yanit.ok || ! (yanit.headers.get('content-type') ?? '').includes('text/html')) {
            form.submit();

            return;
        }

        /* Yönlendirme sonrası VARILAN adres yazılır; geçmişe kayıt eklenmez. */
        ciz(await yanit.text(), yanit.url, 'yok');
    } catch {
        form.submit();
    } finally {
        delete document.body.dataset.gecis;
    }
}

/**
 * Bulunulan sayfayı YERİNDE tazeler.
 *
 * `location.reload()` ile aynı sonucu vermez: tarayıcı yenilemesi ekranı
 * beyaza düşürür, tema ve betikleri baştan yükler, açık panelleri ve
 * kaydırma konumunu kaybettirir. Uzun süren bir iş bittiğinde kullanıcıyı
 * baktığı yerden koparmanın bir sebebi yok — sunucu yine tam sayfa üretir,
 * yalnızca gövde yerinde değişir.
 *
 * Geçmişe kayıt EKLENMEZ: tazeleme bir gezinme değil, aynı sayfanın yeni
 * hâli. Eklenseydi "geri" tuşu aynı adrese tekrar tekrar düşerdi.
 */
export function tazele() {
    return git(window.location.href, 'yok');
}

export function init() {
    on('click', 'a[href]', (event, baglanti) => {
        /*
         * Başka bir bileşen (pencere açma, onay penceresi) tıklamayı zaten
         * karşıladıysa karışmayız.
         */
        if (event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        if (! devralinir(baglanti)) {
            return;
        }

        event.preventDefault();
        git(baglanti.href);
    });

    /* Arama ve süzgeç de birer sayfa geçişidir. */
    on('submit', 'form', (event, form) => {
        if (event.defaultPrevented || form.target
            || (form.getAttribute('method') ?? 'get').toLowerCase() !== 'get') {
            return;
        }

        const adres = new URL(form.getAttribute('action') || window.location.href, window.location.href);

        if (! ayniKoken(adres)) {
            return;
        }

        adres.search = new URLSearchParams(new FormData(form)).toString();

        event.preventDefault();
        git(adres.href);
    });

    /* Geri/ileri: adres zaten değişti, yalnızca içerik getirilir. */
    window.addEventListener('popstate', () => {
        git(window.location.href, 'yok');
    });

    /*
     * İlk sayfanın da bir geçmiş kaydı olmalı; yoksa ilk "geri" tuşu boş
     * duruma düşerdi.
     */
    window.history.replaceState({ nsm: true }, '', window.location.href);
}
