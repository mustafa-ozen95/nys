import { $, $$ } from '../lib/dom.js';

/**
 * Kayan şeritte SEÇİLİ öğeye odaklanır.
 *
 * Bölüm menüsü dar ekranda taşar ve en soldan başlar: odaklanılmazsa
 * kullanıcı hangi bölümde olduğunu görmek için kaydırmak zorunda kalır,
 * sağdaki bölümler ("Kullanıcılar" gibi) yarım görünür.
 */
function focusSelected(strip) {
    const selected = $('[aria-current="page"]', strip);

    if (!selected || strip.scrollWidth <= strip.clientWidth) {
        return;
    }

    // Seçili öğe ortalanır; iki yanındaki komşular da görünür kalır.
    strip.scrollLeft = selected.offsetLeft - (strip.clientWidth - selected.offsetWidth) / 2;
}

/**
 * Şeridin EN SAĞDAKİ görünür bölmesi.
 *
 * Görünmeyen bölmeler atlanır: detay paneli çoğu ekranda yalnızca bir kayıt
 * seçiliyken çizilir.
 */
function rightmostSegment(strip) {
    const segments = $$(':scope > .column, :scope > .detail', strip)
        .filter((segment) => getComputedStyle(segment).display !== 'none');

    return segments[segments.length - 1] ?? null;
}

/**
 * Sütun şeridinde doğru bölmeye iner.
 *
 * Şerit dar ekranda yatay kayar ve en soldan başlar: inilmezse telefonda bir
 * switchin firewall ekranını açan kişi önce switch listesini, sonra nesne
 * listesini görür.
 *
 * Kural TEK ve her ekranda aynıdır: en sağdaki bölme, kullanıcının indiği en
 * derin seviyedir.
 *
 *   - Bir komşu/sunucu/blok seçiliyse detay paneli en sağdadır → detay açılır.
 *   - Seçim yoksa detay paneli hiç çizilmez → liste/içerik en sağdadır.
 *   - Switchin alt ekranlarında (portlar, firewall...) içerik en sağdadır.
 *
 * "İçerik sütununa in" gibi bir kural EKRANA GÖRE DEĞİŞİR: BGP'de detay
 * paneli içerik sütunundan SONRA geldiği için seçilen komşunun bilgileri
 * açılmaz, diğer ekranlarda açılır.
 *
 * Yalnızca AÇILIŞTA yapılır; kullanıcının kendi kaydırmasına karışılmaz.
 * Geniş ekranda şerit zaten taşmaz ve hiçbir şey yapılmaz.
 */
function scrollToSegment(strip, target, smooth = false) {
    // Taşma yoksa (masaüstü) kaydırılacak bir şey de yok.
    if (!target || strip.scrollWidth <= strip.clientWidth) {
        return;
    }

    strip.scrollTo({
        left: target.offsetLeft - strip.offsetLeft,
        behavior: smooth ? 'smooth' : 'auto',
    });
}

/**
 * Sonradan AÇILAN detay panellerini de izler.
 *
 * Bazı ekranlarda seçim sayfa yenilemeden yapılır ve detay paneli JS ile
 * görünür olur (portlarda trafik paneli böyle). Kural yalnızca açılışta
 * çalışsaydı o ekranlarda panel açılır ama şerit yerinde kalırdı — kullanıcı
 * seçtiği şeyin bilgisini göremezdi. Kural tektir, tetikleyeni değişir.
 */
function watchOpenedPanels(strip) {
    new MutationObserver((changes) => {
        for (const change of changes) {
            const segment = change.target;

            if (segment.hidden) {
                continue;
            }

            scrollToSegment(strip, rightmostSegment(strip), true);

            return;
        }
    }).observe(strip, {
        attributes: true,
        attributeFilter: ['hidden'],
        subtree: true,
    });
}

export function init() {
    // Yatay kayan HER şerit aynı kurala tabidir: seçili öğe görünür olur.
    // Listede olmayan bir şeritte (ör. sekmeler) /settings/maintenance gibi sondaki
    // bir bölümde seçilinin nerede olduğu görünmez.
    $$('.topbar__nav, .tabs, .pagination').forEach(focusSelected);

    $$('.columns').forEach((strip) => {
        scrollToSegment(strip, rightmostSegment(strip));
        watchOpenedPanels(strip);
    });
}
