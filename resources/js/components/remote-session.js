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

const KEEPALIVE_INTERVAL = 4 * 60 * 1000;
const WINDOW_CHECK_INTERVAL = 2000;
const CLOSE_END = '/ajax/remote-session/disconnect';

const openSessions = new Map();

function csrf() {
    return $('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Sayfa kapanırken çalışır: fetch iptal edilir, sendBeacon edilmez.
 */
function closeBeacon(token) {
    navigator.sendBeacon?.(CLOSE_END, new Blob(
        [JSON.stringify({ token, _token: csrf() })],
        { type: 'application/json' },
    ));
}

function keepAlive(token, popup) {
    const timer = setInterval(async () => {
        // Oturum penceresi kapandıysa canlı tutmanın anlamı yok.
        if (popup?.closed) {
            stop(token);

            return;
        }

        try {
            const answer = await http.post('/ajax/remote-session/keep-alive', { token }, { silent: true });

            if (!answer.alive) {
                stop(token);
            }
        } catch {
            stop(token);
        }
    }, KEEPALIVE_INTERVAL);

    openSessions.set(token, timer);

    watch(token, popup);
}

/**
 * Pencerenin kapandığını yalnızca yoklayarak anlayabiliyoruz; kapanınca
 * kaydın da kapanması için sunucuya haber verilir.
 */
function watch(token, popup) {
    if (!popup) {
        return;
    }

    const check = setInterval(() => {
        if (!popup.closed) {
            return;
        }

        clearInterval(check);
        stop(token);
        http.post(CLOSE_END, { token }, { silent: true }).catch(() => {});
    }, WINDOW_CHECK_INTERVAL);
}

function stop(token) {
    clearInterval(openSessions.get(token));
    openSessions.delete(token);
}

let pagehideBound = false;

export function init() {
    const sessionToken = $('[data-session-token]')?.dataset.sessionToken;

    // Oturum sayfasındaki kapatma düğmesi.
    on('click', '[data-session-close]', async () => {
        if (sessionToken) {
            await http.post(CLOSE_END, { token: sessionToken }, { silent: true }).catch(() => {});
        }

        window.close();
    });

    // Oturum penceresi kapanırsa (çarpı, sekme kapatma, başka adrese gitme).
    // window dinleyicisi bir kez kurulur; init her sayfa geçişinde koşuyor.
    if (sessionToken && ! pagehideBound) {
        pagehideBound = true;
        window.addEventListener('pagehide', () => closeBeacon(sessionToken));
    }

    on('click', '[data-remote-connect]', async (event, button) => {
        button.disabled = true;

        try {
            const session = await http.post(button.dataset.remoteConnect, {
                type: button.dataset.remoteType,
                id: button.dataset.remoteId,
            });

            const popup = window.open(session.url, `oturum-${session.token}`, 'width=1200,height=800');

            keepAlive(session.token, popup);
            emit(`${session.label} oturumu açıldı.`, 'success');
        } finally {
            button.disabled = false;
        }
    });

    // Paneli açan sekme kapanırsa açık oturumlar sunucuda da kapatılır.
    window.addEventListener('pagehide', () => {
        openSessions.forEach((timer, token) => {
            clearInterval(timer);
            closeBeacon(token);
        });
    });
}
