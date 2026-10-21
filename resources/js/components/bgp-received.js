import { $, escapeHtml, on } from '../lib/dom.js';
import { http } from '../lib/http.js';

/**
 * Komşudan alınan prefixleri gösterir: kabul edilenler ve içeri alma
 * politikasının reddettikleri.
 *
 * Cihaza gidilir, bu yüzden sayfa yüklenirken DEĞİL kullanıcı pencereyi
 * açtığında çağrılır. Ayrım sunucuda yapılır; burada yalnızca basma işi var.
 */

function satirlar(yollar) {
    if (yollar.length === 0) {
        return '<p class="text-sm text-muted">Yok.</p>';
    }

    return yollar
        .map((yol) => `<div class="row row--between bgp-row">
            <span class="mono">${escapeHtml(yol.prefix)}</span>
            <span class="text-sm text-muted">${escapeHtml(yol.as_path ?? '—')}</span>
            <span class="text-sm text-muted mono">${escapeHtml(yol.next_hop ?? '—')}</span>
        </div>`)
        .join('');
}

function bas(hedef, yanit) {
    hedef.innerHTML = `
        <div class="stack stack--tight">
            <span class="eyebrow">Kabul edilen (${yanit.accepted.length})</span>
            ${satirlar(yanit.accepted)}
        </div>
        <div class="stack stack--tight">
            <span class="eyebrow">Reddedilen (${yanit.rejected.length})</span>
            <p class="text-sm text-muted">
                Bu prefixler komşu tarafından duyuruluyor ama içeri alma
                politikası kabul etmiyor.
            </p>
            ${satirlar(yanit.rejected)}
        </div>`;
}

async function yukle(dugme) {
    const hedef = $(dugme.dataset.alinanHedef);

    if (!hedef) {
        return;
    }

    hedef.innerHTML = '<p class="text-sm text-muted">Cihazdan okunuyor…</p>';

    try {
        bas(hedef, await http.get(dugme.dataset.alinan));
    } catch {
        hedef.innerHTML = '<p class="text-sm text-muted">Cihazdan okunamadı.</p>';
    }
}

export function init() {
    on('click', '[data-alinan]', (event, dugme) => {
        yukle(dugme).catch(() => {});
    });
}
