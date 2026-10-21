import { $, $$ } from '../lib/dom.js';

/**
 * Kayan şeritte SEÇİLİ öğeye odaklanır.
 *
 * Bölüm menüsü dar ekranda taşar ve en soldan başlar: odaklanılmazsa
 * kullanıcı hangi bölümde olduğunu görmek için kaydırmak zorunda kalır,
 * sağdaki bölümler ("Kullanıcılar" gibi) yarım görünür.
 */
function seciliyeOdaklan(serit) {
    const secili = $('[aria-current="page"]', serit);

    if (!secili || serit.scrollWidth <= serit.clientWidth) {
        return;
    }

    // Seçili öğe ortalanır; iki yanındaki komşular da görünür kalır.
    serit.scrollLeft = secili.offsetLeft - (serit.clientWidth - secili.offsetWidth) / 2;
}

/**
 * Şeridin EN SAĞDAKİ görünür bölmesi.
 *
 * Görünmeyen bölmeler atlanır: detay paneli çoğu ekranda yalnızca bir kayıt
 * seçiliyken çizilir.
 */
function enSagdakiBolme(serit) {
    const bolmeler = $$(':scope > .column, :scope > .detail', serit)
        .filter((bolme) => getComputedStyle(bolme).display !== 'none');

    return bolmeler[bolmeler.length - 1] ?? null;
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
function bolmeyeIn(serit, hedef, yumusak = false) {
    // Taşma yoksa (masaüstü) kaydırılacak bir şey de yok.
    if (!hedef || serit.scrollWidth <= serit.clientWidth) {
        return;
    }

    serit.scrollTo({
        left: hedef.offsetLeft - serit.offsetLeft,
        behavior: yumusak ? 'smooth' : 'auto',
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
function acilanPanelleriIzle(serit) {
    new MutationObserver((degisimler) => {
        for (const degisim of degisimler) {
            const bolme = degisim.target;

            if (bolme.hidden) {
                continue;
            }

            bolmeyeIn(serit, enSagdakiBolme(serit), true);

            return;
        }
    }).observe(serit, {
        attributes: true,
        attributeFilter: ['hidden'],
        subtree: true,
    });
}

export function init() {
    // Yatay kayan HER şerit aynı kurala tabidir: seçili öğe görünür olur.
    // Listede olmayan bir şeritte (ör. sekmeler) /ayarlar/bakim gibi sondaki
    // bir bölümde seçilinin nerede olduğu görünmez.
    $$('.topbar__nav, .tabs, .pagination').forEach(seciliyeOdaklan);

    $$('.columns').forEach((serit) => {
        bolmeyeIn(serit, enSagdakiBolme(serit));
        acilanPanelleriIzle(serit);
    });
}
