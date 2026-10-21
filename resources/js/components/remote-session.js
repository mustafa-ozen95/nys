import { $, on } from '../lib/dom.js';
import { http } from '../lib/http.js';
import { emit } from './toast.js';

/**
 * Uzak oturum açma ve kapatma.
 *
 * Yalnızca sunucuya haber verir ve dönen adresi yeni pencerede açar; hangi
 * portun ayrılacağına, oturumun ne kadar yaşayacağına ve yetkiye sunucu karar
 * verir.
 *
 * Kapatma üç yoldan tetiklenir:
 *   1. Oturum sayfasındaki "Oturumu kapat" düğmesi,
 *   2. Oturum penceresinin kapanması (pagehide — çarpıya basmak dahil),
 *   3. Paneli açan sekmenin kapanması.
 *
 * Üçü de aynı ucu çağırır; sunucu ikinci çağrıda kayıt açmaz.
 */

const CANLI_TUTMA_ARALIGI = 4 * 60 * 1000;
const PENCERE_KONTROL_ARALIGI = 2000;
const KAPATMA_UCU = '/ajax/uzak-oturum/kapat';

const acikOturumlar = new Map();

function csrf() {
    return $('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Sayfa kapanırken çalışır: fetch iptal edilir, sendBeacon edilmez.
 */
function kapatBeacon(token) {
    navigator.sendBeacon?.(KAPATMA_UCU, new Blob(
        [JSON.stringify({ token, _token: csrf() })],
        { type: 'application/json' },
    ));
}

function canliTut(token, pencere) {
    const zamanlayici = setInterval(async () => {
        // Oturum penceresi kapandıysa canlı tutmanın anlamı yok.
        if (pencere?.closed) {
            durdur(token);

            return;
        }

        try {
            const cevap = await http.post('/ajax/uzak-oturum/canli-tut', { token }, { silent: true });

            if (!cevap.alive) {
                durdur(token);
            }
        } catch {
            durdur(token);
        }
    }, CANLI_TUTMA_ARALIGI);

    acikOturumlar.set(token, zamanlayici);

    izle(token, pencere);
}

/**
 * Pencerenin kapandığını yalnızca yoklayarak anlayabiliyoruz; kapanınca
 * kaydın da kapanması için sunucuya haber verilir.
 */
function izle(token, pencere) {
    if (!pencere) {
        return;
    }

    const kontrol = setInterval(() => {
        if (!pencere.closed) {
            return;
        }

        clearInterval(kontrol);
        durdur(token);
        http.post(KAPATMA_UCU, { token }, { silent: true }).catch(() => {});
    }, PENCERE_KONTROL_ARALIGI);
}

function durdur(token) {
    clearInterval(acikOturumlar.get(token));
    acikOturumlar.delete(token);
}

let pagehideBagli = false;

export function init() {
    const jeton = $('[data-oturum-jetonu]')?.dataset.oturumJetonu;

    // Oturum sayfasındaki kapatma düğmesi.
    on('click', '[data-oturum-kapat]', async () => {
        if (jeton) {
            await http.post(KAPATMA_UCU, { token: jeton }, { silent: true }).catch(() => {});
        }

        window.close();
    });

    // Oturum penceresi kapanırsa (çarpı, sekme kapatma, başka adrese gitme).
    // window dinleyicisi bir kez kurulur; init her sayfa geçişinde koşuyor.
    if (jeton && ! pagehideBagli) {
        pagehideBagli = true;
        window.addEventListener('pagehide', () => kapatBeacon(jeton));
    }

    on('click', '[data-uzak-baglan]', async (event, dugme) => {
        dugme.disabled = true;

        try {
            const oturum = await http.post(dugme.dataset.uzakBaglan, {
                type: dugme.dataset.uzakTur,
                id: dugme.dataset.uzakKimlik,
            });

            const pencere = window.open(oturum.url, `oturum-${oturum.token}`, 'width=1200,height=800');

            canliTut(oturum.token, pencere);
            emit(`${oturum.label} oturumu açıldı.`, 'success');
        } finally {
            dugme.disabled = false;
        }
    });

    // Paneli açan sekme kapanırsa açık oturumlar sunucuda da kapatılır.
    window.addEventListener('pagehide', () => {
        acikOturumlar.forEach((zamanlayici, token) => {
            clearInterval(zamanlayici);
            kapatBeacon(token);
        });
    });
}
