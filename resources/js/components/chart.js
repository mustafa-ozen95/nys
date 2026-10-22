import { $, $$, on } from '../lib/dom.js';
import { http } from '../lib/http.js';

/**
 * Port trafiği grafiği.
 *
 * Bu modül yalnızca DOM ve veri getirme işi yapar: hangi aralığın kaç saniye
 * olduğuna, verinin özetlenip özetlenmediğine ve birimlere sunucu karar verir.
 */

let charts = [];
let activeEndpoint = null;
let activeInterval = null;
let request = null;

async function draw(endpoint, range) {
    const panel = $('[data-traffic-panel]');
    const status = $('[data-traffic-status]');
    const field = $('[data-traffic-field]');

    if (!panel || !field) {
        return;
    }

    request?.abort();
    request = new AbortController();

    status.hidden = false;
    status.textContent = 'Yükleniyor…';
    field.hidden = true;

    let data;

    try {
        data = await http.get(`${endpoint}?range=${encodeURIComponent(range)}`, { signal: request.signal, silent: true });
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }

        status.textContent = 'Trafik verisi alınamadı.';
        clean();

        return;
    }

    if (data.labels.length === 0) {
        status.textContent = 'Bu aralıkta veri yok.';
        clean();

        return;
    }

    status.hidden = true;
    field.hidden = false;
    render(data);
}

function clean() {
    charts.forEach((chart) => chart.destroy());
    charts = [];
}

/**
 * İki grafik aynı zaman eksenini paylaşır: birinin üzerinde durulan an,
 * diğerinde de aynı noktayı gösterir. Böylece gelen ve giden değeri aynı
 * anda okunur.
 */
function sync(source, order) {
    charts.forEach((chart) => {
        if (chart === source) {
            return;
        }

        if (order === null) {
            chart.setActiveElements([]);
            chart.tooltip.setActiveElements([], { x: 0, y: 0 });
        } else {
            const point = { datasetIndex: 0, index: order };
            const element = chart.getDatasetMeta(0).data[order];

            if (!element) {
                return;
            }

            chart.setActiveElements([point]);
            chart.tooltip.setActiveElements([point], { x: element.x, y: element.y });
        }

        chart.update('none');
    });
}

/** Sunucunun verdiği ton adını tasarım token'ına çevirir. */
function toneColor(style, tone) {
    const token = tone === 'accent' ? '--color-accent' : `--color-${tone}-fg`;

    return style.getPropertyValue(token).trim();
}

/** Sunucunun seçtiği ölçeğe göre okunabilir etiket. */
function scaled(value, scale) {
    const split = value / scale.divisor;

    // Küçük değerlerde basamak açılmazsa eksen aynı etiketi tekrarlıyor
    // ("1 bps, 1 bps"). Sondaki sıfırlar yazılmaz.
    const digit = split >= 100 ? 0 : split >= 10 ? 1 : 2;

    return `${split.toLocaleString('tr-TR', {
        minimumFractionDigits: 0,
        maximumFractionDigits: digit,
    })} ${scale.unit}`;
}

function render(data) {
    const style = getComputedStyle(document.documentElement);
    const secondary = style.getPropertyValue('--color-text-muted').trim();
    const line = style.getPropertyValue('--color-line').trim();
    const scale = data.scale;

    clean();

    // Her seri kendi grafiğinde: eksenler bağımsız olunca küçük olan da okunur.
    charts = data.series.map((series, order) => {
        const canvas = $(`[data-traffic-canvas="${order}"]`);
        const header = $(`[data-traffic-title="${order}"]`);

        if (!canvas) {
            return null;
        }

        if (header) {
            header.textContent = series.label;
        }

        const color = toneColor(style, series.tone);

        return new window.Chart(canvas, {
            type: 'line',
            data: {
                labels: data.labels.map((clock) => new Date(clock * 1000).toLocaleTimeString('tr-TR', {
                    hour: '2-digit',
                    minute: '2-digit',
                })),
                datasets: [{
                    label: series.label,
                    data: series.values,
                    borderColor: color,
                    backgroundColor: color,
                    borderWidth: 1.6,
                    pointRadius: 0,
                    tension: 0.25,
                    fill: false,

                    // Ölçüm alınmamış anlar null gelir (izleme sistemi
                    // değişmeyen değeri saklamayabiliyor). Çizgi boşlukların
                    // üzerinden geçer; aksi hâlde seyrek seri kesik kesik
                    // görünür ve okunmaz.
                    spanGaps: true,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 100,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                onHover: (event, elements, chart) => {
                    sync(chart, elements.length ? elements[0].index : null);
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: secondary, maxTicksLimit: 4 } },
                    y: {
                        beginAtZero: true,
                        min: 0,
                        grid: { color: line },
                        ticks: {
                            color: secondary,
                            maxTicksLimit: 3,
                            callback: (value) => scaled(value, scale),
                        },
                    },
                },
                plugins: {
                    // Başlık figcaption'da; ayrıca lejant yer kaplamasın.
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${scaled(context.parsed.y, scale)}`,
                        },
                    },
                },
            },
        });
    }).filter(Boolean);

    // Tarayıcı testleri iki grafiğin eşleşip eşleşmediğini buradan okur.
    window.__trafficCharts = charts;
}

/** Satırı seçip trafik panelini açar. */
function open(row) {
    const panel = $('[data-traffic-panel]');

    $$('[data-port-traffic]').forEach((s) => s.removeAttribute('aria-selected'));
    row.setAttribute('aria-selected', 'true');

    activeEndpoint = row.dataset.portTraffic;
    activeInterval ??= $('[data-traffic-range]')?.dataset.trafficRange;

    $('[data-traffic-header]').textContent = row.dataset.portName;

    const edit = $('[data-port-edit]');

    if (edit) {
        edit.dataset.modalOpen = `port-${row.dataset.portId}`;
    }

    panel.hidden = false;

    draw(activeEndpoint, activeInterval);
}

export function init() {
    const panel = $('[data-traffic-panel]');

    if (!panel) {
        return;
    }

    // İmleç grafiklerden çıkınca iki taraftaki vurgu da kalkar.
    /*
     * mouseleave KABARMAZ; delege edilemez, doğrudan bağlanır. Panel her
     * sayfa geçişinde yeni bir düğüm olduğu için işaret düğümün üstünde
     * tutulur — aynı düğüme iki kez bağlanmasın.
     */
    if (panel.dataset.logoutBound !== undefined) {
        return;
    }

    panel.dataset.logoutBound = '';

    panel.addEventListener('mouseleave', () => {
        charts.forEach((chart) => {
            chart.setActiveElements([]);
            chart.tooltip.setActiveElements([], { x: 0, y: 0 });
            chart.update('none');
        });
    });

    // Sunucu bir portu seçili işaretlediyse (genel bakıştan gelindiğinde)
    // panel doğrudan açılır.
    const auto = $('[data-port-auto]');

    if (auto) {
        open(auto);
        auto.scrollIntoView({ block: 'center' });
    }

    on('click', '[data-port-traffic]', (event, row) => open(row));

    on('click', '[data-traffic-range]', (event, button) => {
        $$('[data-traffic-range]').forEach((b) => b.setAttribute('aria-pressed', 'false'));
        button.setAttribute('aria-pressed', 'true');

        activeInterval = button.dataset.trafficRange;

        if (activeEndpoint) {
            draw(activeEndpoint, activeInterval);
        }
    });
}
