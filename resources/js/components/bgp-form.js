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

function field(form, name) {
    return form.querySelector(`[name="${name}"]`);
}

/**
 * Onay kutusu ARANIRKEN tür de belirtilir.
 *
 * İşaretsiz kutunun "0" göndermesi için her kutunun önünde AYNI ADI taşıyan
 * gizli bir input durur. Yalnızca ada bakan bir sorgu o gizli inputu bulur ve
 * kutu her zaman "işaretsiz" görünürdü — multihop açılınca TTL alanı hiç
 * açılmazdı.
 */
function marked(form, name) {
    const box = form.querySelector(`[name="${name}"][type="checkbox"]`);

    return box instanceof HTMLInputElement && box.checked;
}

/**
 * Elemanın bütün koşulları sağlanıyor mu?
 */
function visible(form, element) {
    const type = element.dataset.bgpType;

    if (type && !type.split(',').includes(field(form, 'type')?.value ?? '')) {
        return false;
    }

    const approval = element.dataset.bgpApproval;

    if (approval && !marked(form, approval)) {
        return false;
    }

    const approvalNot = element.dataset.bgpApprovalNot;

    if (approvalNot && marked(form, approvalNot)) {
        return false;
    }

    return true;
}

/**
 * Gizlenen alanın girdileri devre dışı bırakılır: açık kalan alan sunucuya
 * çelişkili değer gönderir ve istek reddedilir.
 */
function apply(form) {
    $$('[data-bgp-type], [data-bgp-approval], [data-bgp-approval-not]', form).forEach((element) => {
        const open = visible(form, element);

        element.hidden = !open;

        $$('input, select, textarea', element).forEach((input) => {
            input.disabled = !open;
        });
    });
}

function forms() {
    return $$('[data-bgp-form]');
}

export function init() {
    forms().forEach(apply);

    on('change', '[data-bgp-form]', (event, form) => apply(form));
}
