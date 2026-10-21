import { $$, on } from '../lib/dom.js';

/**
 * BGP grup ve komşu formlarının görünürlüğü.
 *
 * Junos'ta bazı alanlar birbirini dışlar ve panel yalnızca cihazın kabul
 * edeceğini sormalıdır:
 *
 *   - peer-as yalnızca external grupta anlamlıdır
 *   - multihop TTL yalnızca multihop açıkken
 *   - prefix sınırı alanları yalnızca aile açıkken
 *   - teardown eşiği ve bekleme yalnızca "oturumu düşür" açıkken
 *   - süresiz bekleme seçiliyse dakika sorulmaz
 *
 * Görünürlük TEK yerde hesaplanır: bir alan ancak BÜTÜN koşulları
 * sağlıyorsa görünür. İki ayrı mekanizma biri diğerini ezerdi (bkz.
 * address-assignment.js).
 *
 * Aynı kanca komşu formunda da kullanılır: Junos grup ayarlarının hemen
 * hepsini komşu seviyesinde de kabul eder, dolayısıyla oradaki koşullar da
 * aynıdır.
 *
 * Burada doğrulama YOKTUR; aynı kısıtlar sunucuda da durur
 * (BgpGroupRequest, BgpNeighborRequest).
 */

function alan(form, ad) {
    return form.querySelector(`[name="${ad}"]`);
}

/**
 * Onay kutusu ARANIRKEN tür de belirtilir.
 *
 * İşaretsiz kutunun "0" göndermesi için her kutunun önünde AYNI ADI taşıyan
 * gizli bir input durur. Yalnızca ada bakan bir sorgu o gizli inputu bulur ve
 * kutu her zaman "işaretsiz" görünürdü — multihop açılınca TTL alanı hiç
 * açılmazdı.
 */
function isaretli(form, ad) {
    const kutu = form.querySelector(`[name="${ad}"][type="checkbox"]`);

    return kutu instanceof HTMLInputElement && kutu.checked;
}

/**
 * Elemanın bütün koşulları sağlanıyor mu?
 */
function gorunur(form, eleman) {
    const tur = eleman.dataset.bgpTur;

    if (tur && !tur.split(',').includes(alan(form, 'type')?.value ?? '')) {
        return false;
    }

    const onay = eleman.dataset.bgpOnay;

    if (onay && !isaretli(form, onay)) {
        return false;
    }

    const onayDegil = eleman.dataset.bgpOnayDegil;

    if (onayDegil && isaretli(form, onayDegil)) {
        return false;
    }

    return true;
}

/**
 * Gizlenen alanın girdileri devre dışı bırakılır: açık kalan alan sunucuya
 * çelişkili değer gönderir ve istek reddedilir.
 */
function uygula(form) {
    $$('[data-bgp-tur], [data-bgp-onay], [data-bgp-onay-degil]', form).forEach((eleman) => {
        const acik = gorunur(form, eleman);

        eleman.hidden = !acik;

        $$('input, select, textarea', eleman).forEach((girdi) => {
            girdi.disabled = !acik;
        });
    });
}

function formlar() {
    return $$('[data-bgp-formu]');
}

export function init() {
    formlar().forEach(uygula);

    on('change', '[data-bgp-formu]', (event, form) => uygula(form));
}
