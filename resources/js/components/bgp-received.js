import { $, escapeHtml, on } from '../lib/dom.js';
import { http } from '../lib/http.js';

/**
 * Komşudan alınan prefixleri gösterir: kabul edilenler ve içeri alma
 * politikasının reddettikleri.
 *
 * Cihaza gidilir, bu yüzden sayfa yüklenirken DEĞİL kullanıcı pencereyi
 * açtığında çağrılır. Ayrım sunucuda yapılır; burada yalnızca basma işi var.
 */

function rows(paths) {
    if (paths.length === 0) {
        return '<p class="text-sm text-muted">Yok.</p>';
    }

    return paths
        .map((path) => `<div class="row row--between bgp-row">
            <span class="mono">${escapeHtml(path.prefix)}</span>
            <span class="text-sm text-muted">${escapeHtml(path.as_path ?? '—')}</span>
            <span class="text-sm text-muted mono">${escapeHtml(path.next_hop ?? '—')}</span>
        </div>`)
        .join('');
}

function head(target, response) {
    target.innerHTML = `
        <div class="stack stack--tight">
            <span class="eyebrow">Kabul edilen (${response.accepted.length})</span>
            ${rows(response.accepted)}
        </div>
        <div class="stack stack--tight">
            <span class="eyebrow">Reddedilen (${response.rejected.length})</span>
            <p class="text-sm text-muted">
                Bu prefixler komşu tarafından duyuruluyor ama içeri alma
                politikası kabul etmiyor.
            </p>
            ${rows(response.rejected)}
        </div>`;
}

async function load(button) {
    const target = $(button.dataset.receivedTarget);

    if (!target) {
        return;
    }

    target.innerHTML = '<p class="text-sm text-muted">Cihazdan okunuyor…</p>';

    try {
        head(target, await http.get(button.dataset.received));
    } catch {
        target.innerHTML = '<p class="text-sm text-muted">Cihazdan okunamadı.</p>';
    }
}

export function init() {
    on('click', '[data-received]', (event, button) => {
        load(button).catch(() => {});
    });
}
