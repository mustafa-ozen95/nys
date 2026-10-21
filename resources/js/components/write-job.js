import { $ } from '../lib/dom.js';
import { tazele } from './navigation.js';
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
const ILK_GECIKME_MS = 1500;
const ARALIK_MS = 2500;
const AZAMI_SURE_MS = 10 * 60 * 1000;

export function init() {
    const host = $('.toast-host');
    const url = host?.dataset.writeJobUrl;

    if (!url) {
        return;
    }

    const baslangic = Date.now();

    const yokla = async () => {
        if (Date.now() - baslangic > AZAMI_SURE_MS) {
            return;
        }

        let veri;

        try {
            const yanit = await fetch(url, { headers: { Accept: 'application/json' } });

            if (!yanit.ok) {
                return;
            }

            veri = await yanit.json();
        } catch {
            // Geçici bir ağ hatası yoklamayı bitirmesin.
            setTimeout(yokla, ARALIK_MS);

            return;
        }

        if (!veri.finished) {
            setTimeout(yokla, ARALIK_MS);

            return;
        }

        emit(
            veri.message ? `${veri.summary}: ${veri.message}` : `${veri.summary} — ${veri.label}`,
            veri.status === 'failed' ? 'danger' : (veri.status === 'completed' ? 'success' : 'info'),
        );

        /*
         * Cihazdan geri okuma iş içinde yapıldı; ekrandaki veri eski.
         * Başarılı yazmadan sonra sayfa YERİNDE tazelenir — sayfa
         * geçişlerindeki gibi. Tarayıcı yenilemesi ekranı beyaza düşürür,
         * açık panelleri kapatır ve az önce basılan bildirimi siler.
         */
        if (veri.status === 'completed') {
            setTimeout(tazele, 1200);
        }
    };

    setTimeout(yokla, ILK_GECIKME_MS);
}
