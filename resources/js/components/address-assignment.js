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

const bellek = new Map();

function alan(form, ad) {
    return form.querySelector(`[name="${ad}"]`);
}

function secilenMetin(select) {
    return select?.value ? (select.selectedOptions[0]?.textContent ?? '').trim() : null;
}

/**
 * Anons ayrı bir alan değildir: blok omurgadaki bir prefix list'e girmişse
 * anons ediliyordur.
 */
function anonsMu(form) {
    return (alan(form, 'connection_type')?.value ?? '') === 'prefix_list';
}

/**
 * Anons edilebilecek en dar IPv4 prefixi /24'tür; daha darları listede
 * seçilemez olur. Kararı yine sunucu verir, bu yalnızca gereksiz hatayı
 * önler.
 */
const EN_DAR_ANONS = 24;

function maskeleri(form, anons) {
    const maske = alan(form, 'prefix_length');

    if (!maske) {
        return;
    }

    Array.from(maske.options).forEach((secenek) => {
        secenek.disabled = anons && Number(secenek.value) > EN_DAR_ANONS;
    });

    if (anons && Number(maske.value) > EN_DAR_ANONS) {
        maske.value = String(EN_DAR_ANONS);
    }
}

/**
 * Form adım adım açılır ve her alanın görünürlüğü TEK yerde hesaplanır.
 *
 * İki ayrı mekanizma (yönteme göre gizle, adıma göre aç) biri diğerini ezer:
 * yöntem VLAN iken "Port" alanı, erişim switchi seçildiği için geri açılırdı.
 * Bu yüzden bir alan ancak BÜTÜN koşulları sağlıyorsa görünür:
 *
 *   data-atama-alani        → seçili yöntem bu listede mi
 *   data-atama-hedef        → seçili yöntem tam olarak bu mu
 *   data-atama-atlama-alani → seçili sonraki-atlama kaynağı bu mu
 *   data-atama-adim         → önceki soru cevaplandı mı
 *
 * Gizlenen alanın girdileri devre dışı bırakılır; açık kalan bir alan
 * sunucuya çelişkili değer gönderir.
 */
function goster(form) {
    const tur = alan(form, 'connection_type')?.value ?? '';
    const kaynak = form.querySelector('[data-atama-atlama]:checked')?.value ?? null;

    maskeleri(form, anonsMu(form));

    const cevaplandi = {
        tur: Boolean(tur),
        omurga: Boolean(alan(form, 'core_switch_id')?.value),
        // Erişim switchi yalnızca "omurgada" tiki kapalıyken sorulur.
        erisimSorulur: !omurgadaMi(form),
        // VLAN ve port omurgada da olabilir: hedef listesi için erişim
        // switchi şart değil, omurga da yeter.
        hedefSwitch: Boolean(hedefSwitchId(form)),
        atlama: Boolean(kaynak),
    };

    const secici = '[data-atama-alani], [data-atama-hedef], [data-atama-atlama-alani], [data-atama-adim]';

    // Belge sırası: ata blok kendinden önce gelir, çocuk ona bakabilir.
    $$(secici, form).forEach((kutu) => {
        const veri = kutu.dataset;

        let gorunur = true;

        if (veri.atamaAlani) {
            gorunur = gorunur && veri.atamaAlani.split(' ').includes(tur);
        }

        if (veri.atamaHedef) {
            gorunur = gorunur && veri.atamaHedef === tur;
        }

        if (veri.atamaAtlamaAlani) {
            gorunur = gorunur && veri.atamaAtlamaAlani === kaynak;
        }

        if (veri.atamaAdim) {
            gorunur = gorunur && (cevaplandi[veri.atamaAdim] ?? false);
        }

        if (gorunur && kutu.parentElement?.closest('[hidden]')) {
            gorunur = false;
        }

        kutu.hidden = !gorunur;
        kutu.querySelectorAll('select, input').forEach((girdi) => {
            girdi.disabled = !gorunur;
        });
    });
}

function doldur(select, secenekler, seciliDeger) {
    if (!select) {
        return;
    }

    const bos = document.createElement('option');
    bos.value = '';
    bos.textContent = secenekler.length ? 'Seçiniz' : 'Kayıt yok';

    const liste = secenekler.map((secenek) => {
        const option = document.createElement('option');
        option.value = secenek.value;
        option.textContent = secenek.label;
        option.selected = String(secenek.value) === String(seciliDeger);

        return option;
    });

    select.replaceChildren(bos, ...liste);
    select.disabled = secenekler.length === 0;
}

/**
 * "Omurga switchte" tiki işaretliyse erişim switchi hiç sorulmaz.
 */
function omurgadaMi(form) {
    return form.querySelector('[data-atama-omurgada]')?.checked ?? false;
}

/**
 * Hedeflerin okunacağı switch: erişim switchi soruluyorsa o, değilse omurga.
 * VLAN ve port yalnızca erişim switchinde olmak zorunda değil.
 *
 * Gizlenen seçicinin değeri DOM'da durmaya devam eder; devre dışıysa yok
 * sayılır, yoksa tik konduktan sonra da eski erişim switchi kullanılırdı.
 */
function hedefSwitchId(form) {
    const erisim = alan(form, 'access_switch_id');
    const erisimId = erisim && !erisim.disabled ? erisim.value : '';

    return erisimId || alan(form, 'core_switch_id')?.value || '';
}

async function hedefleri(form) {
    const kutu = form.querySelector('[data-atama-hedef-ucu]');
    const vlan = alan(form, 'vlan_id');
    const port = alan(form, 'port_name');

    if (!kutu || !vlan || !port) {
        return;
    }

    const switchId = hedefSwitchId(form);

    if (!switchId) {
        doldur(vlan, []);
        doldur(port, []);

        return;
    }

    const url = kutu.dataset.atamaHedefUcu.replace('__id__', switchId);

    if (!bellek.has(url)) {
        bellek.set(url, http.get(url));
    }

    const hedefler = await bellek.get(url);

    doldur(vlan, hedefler.vlans, vlan.value || vlan.dataset.atamaSecili);
    doldur(port, hedefler.ports, port.value || port.dataset.atamaSecili);
}

/**
 * Onay penceresinde okunacak blok: yeni blokta adres + maske, alt ağda
 * seçilen yuva, düzenlemede zaten belli.
 */
function blokAdi(form) {
    if (form.dataset.atamaBlok) {
        return form.dataset.atamaBlok;
    }

    /*
     * Adresteki maske ATILIR. Kullanıcı "10.9.9.0/24" yazdığında birleştirme
     * "10.9.9.0/24/24" üretir ve onay penceresi kullanıcıdan o anlamsız metni
     * yazmasını isterdi — ekranda hata olmaz, yapılabilecek bir şey de.
     *
     * Yazılan maske burada SESSİZCE KABUL EDİLMEZ; alan zaten reddediliyor
     * (bkz. maskeDenetle). Buradaki tek iş, onay metninin saçmalamaması.
     */
    const adres = alan(form, 'network')?.value?.trim()?.split('/')[0];
    const maske = alan(form, 'prefix_length')?.value;

    return adres && maske ? `${adres}/${maske}` : '';
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
function maskeDenetle(form) {
    const alanAdres = alan(form, 'network');

    if (!alanAdres) {
        return;
    }

    const deger = alanAdres.value.trim();

    if (!deger.includes('/')) {
        alanAdres.setCustomValidity('');

        return;
    }

    const [adres, maske] = deger.split('/');

    alanAdres.setCustomValidity(
        `Bu alana yalnızca ağ adresi yazılır, maske ayrı seçilir: "${adres}" yazıp `
        + `maskeyi yandaki listeden seçin${maske ? ` (/${maske})` : ''}.`,
    );
}

/**
 * Seçim etiketi "sw-test-1 · Erişim" biçimindedir; cümle içinde yalnızca switch
 * adı kullanılır, rol ayrıntı satırında zaten yazar.
 */
function switchAdi(etiket) {
    return etiket ? etiket.split(' · ')[0] : null;
}

function cumle(blok, omurga, erisim, routeVar, asn) {
    if (asn) {
        const uzerinden = omurga ? `${switchAdi(omurga)} üzerinden ` : '';

        return `${blok} prefixi ${uzerinden}${asn} ile anons edilecek.`;
    }

    if (omurga && erisim) {
        const fiil = routeVar ? 'route edilecek' : 'tanımlanacak';

        return `${blok} bloğu ${switchAdi(omurga)} üzerinden ${switchAdi(erisim)} switchine ${fiil}.`;
    }

    if (erisim) {
        const fiil = routeVar ? 'route edilecek' : 'tanımlanacak';

        return `${blok} bloğu ${switchAdi(erisim)} switchine ${fiil}.`;
    }

    if (omurga) {
        return `${blok} bloğu ${switchAdi(omurga)} omurga switchinde tanımlanacak.`;
    }

    return `${blok} bloğu kaydedilecek. Herhangi bir switche atanmayacak.`;
}

function ozetle(form) {
    const blok = blokAdi(form);

    if (!blok) {
        delete form.dataset.confirm;

        return;
    }

    const omurga = secilenMetin(alan(form, 'core_switch_id'));
    const erisim = secilenMetin(alan(form, 'access_switch_id'));
    const asn = alan(form, 'asn')?.value?.trim() || null;
    const tur = alan(form, 'connection_type')?.value ?? '';
    const hedef = {
        vlan: () => secilenMetin(alan(form, 'vlan_id')),
        port: () => secilenMetin(alan(form, 'port_name')),
        next_hop: () => {
            const secili = form.querySelector('[data-atama-atlama]:checked');

            return secili?.value === 'manual'
                ? (alan(form, 'next_hop')?.value?.trim() || null)
                : secilenMetin(alan(form, 'next_hop_switch'));
        },
        prefix_list: () => secilenMetin(alan(form, 'prefix_list_id')),
    }[tur]?.() ?? null;

    // Yol tek satırda okunur: omurga → erişim → VLAN/port.
    const zincir = [
        omurga,
        erisim,
        tur && hedef
            ? `${{ vlan: 'VLAN', port: 'Port', next_hop: 'Sonraki atlama', prefix_list: 'Prefix list' }[tur]} ${hedef}`
            : null,
        asn ? `Anons ${asn}` : null,
    ].filter(Boolean);

    form.dataset.confirmTitle = 'Bloğu onayla';
    form.dataset.confirm = [
        cumle(blok, omurga, erisim, Boolean(tur && hedef), asn),
        zincir.length ? zincir.join('  →  ') : null,
    ].filter(Boolean).join('\n\n');
    form.dataset.confirmVerify = blok;
}

function yenile(form) {
    goster(form);
    maskeDenetle(form);
    ozetle(form);

    /*
     * Hedef listeleri dolduktan sonra görünürlük yeniden uygulanır: doldur()
     * seçicileri seçenek varsa etkinleştirir, oysa gizli bir alan gönderilmemeli
     * (port yöntemindeyken VLAN seçicisi gibi).
     */
    hedefleri(form).then(() => {
        goster(form);
        ozetle(form);
    }).catch(() => {});
}

export function init() {
    $$('[data-atama-formu]').forEach(yenile);

    // Hedef listeleri yalnızca erişim switchi değişince yeniden çekilir;
    // her değişiklikte yenilenirse kullanıcının seçtiği port silinir.
    on('change', '[data-atama-formu] [data-atama-erisim]', (event, element) => {
        yenile(element.closest('[data-atama-formu]'));
    });

    // Tik konunca erişim seçimi geride kalmasın; hedefler omurgadan gelir.
    on('change', '[data-atama-formu] [data-atama-omurgada]', (event, element) => {
        const form = element.closest('[data-atama-formu]');
        const erisim = alan(form, 'access_switch_id');

        if (element.checked && erisim) {
            erisim.value = '';
        }

        yenile(form);
    });

    /*
     * Omurga seçilince hem prefix list adımı açılır hem de hedef listesi
     * omurgadan doldurulabilir (erişim switchi seçilmemişse).
     */
    on('change', '[data-atama-formu] [data-atama-omurga]', (event, element) => {
        yenile(element.closest('[data-atama-formu]'));
    });

    on('change', '[data-atama-formu] [data-atama-atlama]', (event, element) => {
        const form = element.closest('[data-atama-formu]');
        goster(form);
        ozetle(form);
    });

    on('change', '[data-atama-formu] [name="connection_type"]', (event, element) => {
        const form = element.closest('[data-atama-formu]');
        goster(form);
        ozetle(form);
    });

    on('change', '[data-atama-formu] select, [data-atama-formu] input', (event, element) => {
        ozetle(element.closest('[data-atama-formu]'));
    });

    on('input', '[data-atama-formu] input', (event, element) => {
        const form = element.closest('[data-atama-formu]');

        maskeDenetle(form);
        ozetle(form);
    });
}
