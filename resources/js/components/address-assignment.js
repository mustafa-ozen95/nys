import { $$, on } from '../lib/dom.js';
import { http } from '../lib/http.js';

/**
 * Adres bloğu formunun istemci tarafı.
 *
 * Üç iş yapar, hiçbiri doğrulama değildir:
 *   1. Bağlantı türüne göre VLAN veya port seçimini gösterir.
 *   2. Seçilen erişim switchinin hedeflerini sunucudan doldurur.
 *   3. Kaydetmeden önce onay penceresine gösterilecek özeti hazırlar.
 *
 * Kararın kendisi sunucudadır; burada yalnızca kullanıcıya ne yapacağını
 * okutan metin üretilir.
 */

const memory = new Map();

function field(form, name) {
    return form.querySelector(`[name="${name}"]`);
}

function selectedText(select) {
    return select?.value ? (select.selectedOptions[0]?.textContent ?? '').trim() : null;
}

/**
 * Anons ayrı bir alan değildir: blok omurgadaki bir prefix list'e girmişse
 * anons ediliyordur.
 */
function isAnnounce(form) {
    return (field(form, 'connection_type')?.value ?? '') === 'prefix_list';
}

/**
 * Anons edilebilecek en dar IPv4 prefixi /24'tür; daha darları listede
 * seçilemez olur. Kararı yine sunucu verir, bu yalnızca gereksiz hatayı
 * önler.
 */
const NARROWEST_ANNOUNCE = 24;

function masks(form, announce) {
    const mask = field(form, 'prefix_length');

    if (!mask) {
        return;
    }

    Array.from(mask.options).forEach((option) => {
        option.disabled = announce && Number(option.value) > NARROWEST_ANNOUNCE;
    });

    if (announce && Number(mask.value) > NARROWEST_ANNOUNCE) {
        mask.value = String(NARROWEST_ANNOUNCE);
    }
}

/**
 * Form adım adım açılır ve her alanın görünürlüğü TEK yerde hesaplanır.
 *
 * İki ayrı mekanizma (yönteme göre gizle, adıma göre aç) biri diğerini ezer:
 * yöntem VLAN iken "Port" alanı, erişim switchi seçildiği için geri açılırdı.
 * Bu yüzden bir alan ancak BÜTÜN koşulları sağlıyorsa görünür:
 *
 *   data-assignment-field        → seçili yöntem bu listede mi
 *   data-assignment-target        → seçili yöntem tam olarak bu mu
 *   data-assignment-skip-field → seçili sonraki-atlama kaynağı bu mu
 *   data-assignment-step         → önceki soru cevaplandı mı
 *
 * Gizlenen alanın girdileri devre dışı bırakılır; açık kalan bir alan
 * sunucuya çelişkili değer gönderir.
 */
function show(form) {
    const type = field(form, 'connection_type')?.value ?? '';
    const source = form.querySelector('[data-assignment-skip]:checked')?.value ?? null;

    masks(form, isAnnounce(form));

    const answered = {
        type: Boolean(type),
        backbone: Boolean(field(form, 'core_switch_id')?.value),
        // Erişim switchi yalnızca "omurgada" tiki kapalıyken sorulur.
        accessAsked: !isBackbone(form),
        // VLAN ve port omurgada da olabilir: hedef listesi için erişim
        // switchi şart değil, omurga da yeter.
        targetSwitch: Boolean(targetSwitchId(form)),
        skip: Boolean(source),
    };

    const selector = '[data-assignment-field], [data-assignment-target], [data-assignment-skip-field], [data-assignment-step]';

    // Belge sırası: ata blok kendinden önce gelir, çocuk ona bakabilir.
    $$(selector, form).forEach((box) => {
        const data = box.dataset;

        let visible = true;

        if (data.assignmentField) {
            visible = visible && data.assignmentField.split(' ').includes(type);
        }

        if (data.assignmentTarget) {
            visible = visible && data.assignmentTarget === type;
        }

        if (data.assignmentSkipField) {
            visible = visible && data.assignmentSkipField === source;
        }

        if (data.assignmentStep) {
            visible = visible && (answered[data.assignmentStep] ?? false);
        }

        if (visible && box.parentElement?.closest('[hidden]')) {
            visible = false;
        }

        box.hidden = !visible;
        box.querySelectorAll('select, input').forEach((input) => {
            input.disabled = !visible;
        });
    });
}

function fill(select, options, selectedValue) {
    if (!select) {
        return;
    }

    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = options.length ? 'Seçiniz' : 'Kayıt yok';

    const list = options.map((item) => {
        const option = document.createElement('option');
        option.value = item.value;
        option.textContent = item.label;
        option.selected = String(item.value) === String(selectedValue);

        return option;
    });

    select.replaceChildren(empty, ...list);
    select.disabled = options.length === 0;
}

/**
 * "Omurga switchte" tiki işaretliyse erişim switchi hiç sorulmaz.
 */
function isBackbone(form) {
    return form.querySelector('[data-assignment-backbone]')?.checked ?? false;
}

/**
 * Hedeflerin okunacağı switch: erişim switchi soruluyorsa o, değilse omurga.
 * VLAN ve port yalnızca erişim switchinde olmak zorunda değil.
 *
 * Gizlenen seçicinin değeri DOM'da durmaya devam eder; devre dışıysa yok
 * sayılır, yoksa tik konduktan sonra da eski erişim switchi kullanılırdı.
 */
function targetSwitchId(form) {
    const access = field(form, 'access_switch_id');
    const accessId = access && !access.disabled ? access.value : '';

    return accessId || field(form, 'core_switch_id')?.value || '';
}

async function targets(form) {
    const box = form.querySelector('[data-assignment-target-end]');
    const vlan = field(form, 'vlan_id');
    const port = field(form, 'port_name');

    if (!box || !vlan || !port) {
        return;
    }

    const switchId = targetSwitchId(form);

    if (!switchId) {
        fill(vlan, []);
        fill(port, []);

        return;
    }

    const url = box.dataset.assignmentTargetEnd.replace('__id__', switchId);

    if (!memory.has(url)) {
        memory.set(url, http.get(url));
    }

    const targets = await memory.get(url);

    fill(vlan, targets.vlans, vlan.value || vlan.dataset.assignmentSelected);
    fill(port, targets.ports, port.value || port.dataset.assignmentSelected);
}

/**
 * Onay penceresinde okunacak blok: yeni blokta adres + maske, alt ağda
 * seçilen yuva, düzenlemede zaten belli.
 */
function blockName(form) {
    if (form.dataset.assignmentBlock) {
        return form.dataset.assignmentBlock;
    }

    /*
     * Adresteki maske ATILIR. Kullanıcı "10.9.9.0/24" yazdığında birleştirme
     * "10.9.9.0/24/24" üretir ve onay penceresi kullanıcıdan o anlamsız metni
     * yazmasını isterdi — ekranda hata olmaz, yapılabilecek bir şey de.
     *
     * Yazılan maske burada SESSİZCE KABUL EDİLMEZ; alan zaten reddediliyor
     * (bkz. maskeDenetle). Buradaki tek iş, onay metninin saçmalamaması.
     */
    const address = field(form, 'network')?.value?.trim()?.split('/')[0];
    const mask = field(form, 'prefix_length')?.value;

    return address && mask ? `${address}/${mask}` : '';
}

/**
 * Ağ adresi alanına maske yazılmasını ANINDA söyler.
 *
 * Sunucu zaten reddeder ama kullanıcı onu ancak formu gönderdikten sonra
 * görür; üstelik araya onay penceresi girer. Tarayıcının kendi
 * doğrulaması gönderimi baştan durdurur ve mesajı alanın yanında gösterir.
 *
 * Kural ve DÜZELTMESİ birlikte söylenir: ne yanlış değil, ne yapılacağı.
 */
function maskCheck(form) {
    const fieldAddress = field(form, 'network');

    if (!fieldAddress) {
        return;
    }

    const value = fieldAddress.value.trim();

    if (!value.includes('/')) {
        fieldAddress.setCustomValidity('');

        return;
    }

    const [address, mask] = value.split('/');

    fieldAddress.setCustomValidity(
        `Bu alana yalnızca ağ adresi yazılır, maske ayrı seçilir: "${address}" yazıp `
        + `maskeyi yandaki listeden seçin${mask ? ` (/${mask})` : ''}.`,
    );
}

/**
 * Seçim etiketi "sw-test-1 · Erişim" biçimindedir; cümle içinde yalnızca switch
 * adı kullanılır, rol ayrıntı satırında zaten yazar.
 */
function switchName(tag) {
    return tag ? tag.split(' · ')[0] : null;
}

function sentence(block, backbone, access, hasRoute, asn) {
    if (asn) {
        const uzerinden = backbone ? `${switchName(backbone)} üzerinden ` : '';

        return `${block} prefixi ${uzerinden}${asn} ile anons edilecek.`;
    }

    if (backbone && access) {
        const verb = hasRoute ? 'route edilecek' : 'tanımlanacak';

        return `${block} bloğu ${switchName(backbone)} üzerinden ${switchName(access)} switchine ${verb}.`;
    }

    if (access) {
        const verb = hasRoute ? 'route edilecek' : 'tanımlanacak';

        return `${block} bloğu ${switchName(access)} switchine ${verb}.`;
    }

    if (backbone) {
        return `${block} bloğu ${switchName(backbone)} omurga switchinde tanımlanacak.`;
    }

    return `${block} bloğu kaydedilecek. Herhangi bir switche atanmayacak.`;
}

function summarize(form) {
    const block = blockName(form);

    if (!block) {
        delete form.dataset.confirm;

        return;
    }

    const backbone = selectedText(field(form, 'core_switch_id'));
    const access = selectedText(field(form, 'access_switch_id'));
    const asn = field(form, 'asn')?.value?.trim() || null;
    const type = field(form, 'connection_type')?.value ?? '';
    const target = {
        vlan: () => selectedText(field(form, 'vlan_id')),
        port: () => selectedText(field(form, 'port_name')),
        next_hop: () => {
            const selected = form.querySelector('[data-assignment-skip]:checked');

            return selected?.value === 'manual'
                ? (field(form, 'next_hop')?.value?.trim() || null)
                : selectedText(field(form, 'next_hop_switch'));
        },
        prefix_list: () => selectedText(field(form, 'prefix_list_id')),
    }[type]?.() ?? null;

    // Yol tek satırda okunur: omurga → erişim → VLAN/port.
    const chain = [
        backbone,
        access,
        type && target
            ? `${{ vlan: 'VLAN', port: 'Port', next_hop: 'Sonraki atlama', prefix_list: 'Prefix list' }[type]} ${target}`
            : null,
        asn ? `Anons ${asn}` : null,
    ].filter(Boolean);

    form.dataset.confirmTitle = 'Bloğu onayla';
    form.dataset.confirm = [
        sentence(block, backbone, access, Boolean(type && target), asn),
        chain.length ? chain.join('  →  ') : null,
    ].filter(Boolean).join('\n\n');
    form.dataset.confirmVerify = block;
}

function refresh(form) {
    show(form);
    maskCheck(form);
    summarize(form);

    /*
     * Hedef listeleri dolduktan sonra görünürlük yeniden uygulanır: doldur()
     * seçicileri seçenek varsa etkinleştirir, oysa gizli bir alan gönderilmemeli
     * (port yöntemindeyken VLAN seçicisi gibi).
     */
    targets(form).then(() => {
        show(form);
        summarize(form);
    }).catch(() => {});
}

export function init() {
    $$('[data-assignment-form]').forEach(refresh);

    // Hedef listeleri yalnızca erişim switchi değişince yeniden çekilir;
    // her değişiklikte yenilenirse kullanıcının seçtiği port silinir.
    on('change', '[data-assignment-form] [data-assignment-access]', (event, element) => {
        refresh(element.closest('[data-assignment-form]'));
    });

    // Tik konunca erişim seçimi geride kalmasın; hedefler omurgadan gelir.
    on('change', '[data-assignment-form] [data-assignment-backbone]', (event, element) => {
        const form = element.closest('[data-assignment-form]');
        const access = field(form, 'access_switch_id');

        if (element.checked && access) {
            access.value = '';
        }

        refresh(form);
    });

    /*
     * Omurga seçilince hem prefix list adımı açılır hem de hedef listesi
     * omurgadan doldurulabilir (erişim switchi seçilmemişse).
     */
    on('change', '[data-assignment-form] [data-assignment-backbone]', (event, element) => {
        refresh(element.closest('[data-assignment-form]'));
    });

    on('change', '[data-assignment-form] [data-assignment-skip]', (event, element) => {
        const form = element.closest('[data-assignment-form]');
        show(form);
        summarize(form);
    });

    on('change', '[data-assignment-form] [name="connection_type"]', (event, element) => {
        const form = element.closest('[data-assignment-form]');
        show(form);
        summarize(form);
    });

    on('change', '[data-assignment-form] select, [data-assignment-form] input', (event, element) => {
        summarize(element.closest('[data-assignment-form]'));
    });

    on('input', '[data-assignment-form] input', (event, element) => {
        const form = element.closest('[data-assignment-form]');

        maskCheck(form);
        summarize(form);
    });
}
