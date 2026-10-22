import { $ } from '../lib/dom.js';
import { refresh } from './navigation.js';
import { emit } from './toast.js';

/**
 * Kuyruğa alınmış cihaz yazmasının sonucunu bekler.
 *
 * Yazma ELS switchte bir dakikaya yaklaşabilir; isteği o kadar tutmasın diye
 * kuyruktadır. Sayfa sonucu yoklar — websocket yok, bu ölçekte ek altyapı ek
 * kırılma noktası demek.
 *
 * Burada iş mantığı YOKTUR: durum ve metin sunucudan hazır gelir.
 */
const FIRST_DELAY_MS = 1500;
const INTERVAL_MS = 2500;
const MAX_DURATION_MS = 10 * 60 * 1000;

export function init() {
    const host = $('.toast-host');
    const url = host?.dataset.writeJobUrl;

    if (!url) {
        return;
    }

    const start = Date.now();

    const poll = async () => {
        if (Date.now() - start > MAX_DURATION_MS) {
            return;
        }

        let data;

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });

            if (!response.ok) {
                return;
            }

            data = await response.json();
        } catch {
            // Geçici bir ağ hatası yoklamayı bitirmesin.
            setTimeout(poll, INTERVAL_MS);

            return;
        }

        if (!data.finished) {
            setTimeout(poll, INTERVAL_MS);

            return;
        }

        emit(
            data.message ? `${data.summary}: ${data.message}` : `${data.summary} — ${data.label}`,
            data.status === 'failed' ? 'danger' : (data.status === 'completed' ? 'success' : 'info'),
        );

        /*
         * Cihazdan geri okuma iş içinde yapıldı; ekrandaki veri eski.
         * Başarılı yazmadan sonra sayfa YERİNDE tazelenir — sayfa
         * geçişlerindeki gibi. Tarayıcı yenilemesi ekranı beyaza düşürür,
         * açık panelleri kapatır ve az önce basılan bildirimi siler.
         */
        if (data.status === 'completed') {
            setTimeout(refresh, 1200);
        }
    };

    setTimeout(poll, FIRST_DELAY_MS);
}
