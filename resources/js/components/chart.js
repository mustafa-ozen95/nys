import { $, $$, on } from '../lib/dom.js';
import { http } from '../lib/http.js';

/**
 * Port trafiği grafiği.
 *
 * Bu modül yalnızca DOM ve veri getirme işi yapar: hangi aralığın kaç saniye
 * olduğuna, verinin özetlenip özetlenmediğine ve birimlere sunucu karar verir.
 */

let charts = [];
let aktifUc = null;
let aktifAralik = null;
let istek = null;

async function ciz(endpoint, range) {
    const panel = $('[data-trafik-paneli]');
    const durum = $('[data-trafik-durumu]');
    const alan = $('[data-trafik-alani]');

    if (!panel || !alan) {
        return;
    }

    istek?.abort();
    istek = new AbortController();

    durum.hidden = false;
    durum.textContent = 'Yükleniyor…';
    alan.hidden = true;

    let veri;

    try {
        veri = await http.get(`${endpoint}?range=${encodeURIComponent(range)}`, { signal: istek.signal, silent: true });
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }

        durum.textContent = 'Trafik verisi alınamadı.';
        temizle();

        return;
    }

    if (veri.labels.length === 0) {
        durum.textContent = 'Bu aralıkta veri yok.';
        temizle();

        return;
    }

    durum.hidden = true;
    alan.hidden = false;
    render(veri);
}

function temizle() {
    charts.forEach((grafik) => grafik.destroy());
    charts = [];
}

/**
 * İki grafik aynı zaman eksenini paylaşır: birinin üzerinde durulan an,
 * diğerinde de aynı noktayı gösterir. Böylece gelen ve giden değeri aynı
 * anda okunur.
 */
function esitle(kaynak, sira) {
    charts.forEach((grafik) => {
        if (grafik === kaynak) {
            return;
        }

        if (sira === null) {
            grafik.setActiveElements([]);
            grafik.tooltip.setActiveElements([], { x: 0, y: 0 });
        } else {
            const nokta = { datasetIndex: 0, index: sira };
            const eleman = grafik.getDatasetMeta(0).data[sira];

            if (!eleman) {
                return;
            }

            grafik.setActiveElements([nokta]);
            grafik.tooltip.setActiveElements([nokta], { x: eleman.x, y: eleman.y });
        }

        grafik.update('none');
    });
}

/** Sunucunun verdiği ton adını tasarım token'ına çevirir. */
function tonRengi(stil, ton) {
    const token = ton === 'accent' ? '--color-accent' : `--color-${ton}-fg`;

    return stil.getPropertyValue(token).trim();
}

/** Sunucunun seçtiği ölçeğe göre okunabilir etiket. */
function olcekli(deger, olcek) {
    const bolunmus = deger / olcek.divisor;

    // Küçük değerlerde basamak açılmazsa eksen aynı etiketi tekrarlıyor
    // ("1 bps, 1 bps"). Sondaki sıfırlar yazılmaz.
    const basamak = bolunmus >= 100 ? 0 : bolunmus >= 10 ? 1 : 2;

    return `${bolunmus.toLocaleString('tr-TR', {
        minimumFractionDigits: 0,
        maximumFractionDigits: basamak,
    })} ${olcek.unit}`;
}

function render(veri) {
    const stil = getComputedStyle(document.documentElement);
    const ikincil = stil.getPropertyValue('--color-text-muted').trim();
    const cizgi = stil.getPropertyValue('--color-line').trim();
    const olcek = veri.scale;

    temizle();

    // Her seri kendi grafiğinde: eksenler bağımsız olunca küçük olan da okunur.
    charts = veri.series.map((seri, sira) => {
        const tuval = $(`[data-trafik-tuvali="${sira}"]`);
        const baslik = $(`[data-trafik-basligi="${sira}"]`);

        if (!tuval) {
            return null;
        }

        if (baslik) {
            baslik.textContent = seri.label;
        }

        const renk = tonRengi(stil, seri.tone);

        return new window.Chart(tuval, {
            type: 'line',
            data: {
                labels: veri.labels.map((clock) => new Date(clock * 1000).toLocaleTimeString('tr-TR', {
                    hour: '2-digit',
                    minute: '2-digit',
                })),
                datasets: [{
                    label: seri.label,
                    data: seri.values,
                    borderColor: renk,
                    backgroundColor: renk,
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
                onHover: (olay, elemanlar, grafik) => {
                    esitle(grafik, elemanlar.length ? elemanlar[0].index : null);
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: ikincil, maxTicksLimit: 4 } },
                    y: {
                        beginAtZero: true,
                        min: 0,
                        grid: { color: cizgi },
                        ticks: {
                            color: ikincil,
                            maxTicksLimit: 3,
                            callback: (deger) => olcekli(deger, olcek),
                        },
                    },
                },
                plugins: {
                    // Başlık figcaption'da; ayrıca lejant yer kaplamasın.
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (baglam) => `${baglam.dataset.label}: ${olcekli(baglam.parsed.y, olcek)}`,
                        },
                    },
                },
            },
        });
    }).filter(Boolean);

    // Tarayıcı testleri iki grafiğin eşleşip eşleşmediğini buradan okur.
    window.__trafikGrafikleri = charts;
}

/** Satırı seçip trafik panelini açar. */
function ac(satir) {
    const panel = $('[data-trafik-paneli]');

    $$('[data-port-trafik]').forEach((s) => s.removeAttribute('aria-selected'));
    satir.setAttribute('aria-selected', 'true');

    aktifUc = satir.dataset.portTrafik;
    aktifAralik ??= $('[data-trafik-araligi]')?.dataset.trafikAraligi;

    $('[data-trafik-baslik]').textContent = satir.dataset.portAdi;

    const duzenle = $('[data-port-duzenle]');

    if (duzenle) {
        duzenle.dataset.modalOpen = `port-${satir.dataset.portKimlik}`;
    }

    panel.hidden = false;

    ciz(aktifUc, aktifAralik);
}

export function init() {
    const panel = $('[data-trafik-paneli]');

    if (!panel) {
        return;
    }

    // İmleç grafiklerden çıkınca iki taraftaki vurgu da kalkar.
    /*
     * mouseleave KABARMAZ; delege edilemez, doğrudan bağlanır. Panel her
     * sayfa geçişinde yeni bir düğüm olduğu için işaret düğümün üstünde
     * tutulur — aynı düğüme iki kez bağlanmasın.
     */
    if (panel.dataset.cikisBagli !== undefined) {
        return;
    }

    panel.dataset.cikisBagli = '';

    panel.addEventListener('mouseleave', () => {
        charts.forEach((grafik) => {
            grafik.setActiveElements([]);
            grafik.tooltip.setActiveElements([], { x: 0, y: 0 });
            grafik.update('none');
        });
    });

    // Sunucu bir portu seçili işaretlediyse (genel bakıştan gelindiğinde)
    // panel doğrudan açılır.
    const otomatik = $('[data-port-otomatik]');

    if (otomatik) {
        ac(otomatik);
        otomatik.scrollIntoView({ block: 'center' });
    }

    on('click', '[data-port-trafik]', (event, satir) => ac(satir));

    on('click', '[data-trafik-araligi]', (event, dugme) => {
        $$('[data-trafik-araligi]').forEach((b) => b.setAttribute('aria-pressed', 'false'));
        dugme.setAttribute('aria-pressed', 'true');

        aktifAralik = dugme.dataset.trafikAraligi;

        if (aktifUc) {
            ciz(aktifUc, aktifAralik);
        }
    });
}
