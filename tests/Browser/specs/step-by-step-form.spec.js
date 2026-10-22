import { expect, test } from '@playwright/test';

import { performLogin } from '../support/login.js';

/**
 * Formların adım adım açıldığını doğrular.
 *
 * Bu hatalar sunucu testlerinden geçiyor: alanlar HTML'de var, yalnızca
 * yanlış anda görünüyorlar. VLAN yöntemi seçiliyken "Uplink IP" alanının
 * açık kalması ve port modunda trunk seçiminin çalışmaması böyle kaçtı.
 */

async function blockDialog(page) {
    await performLogin(page);
    await page.goto('/addressing');
    await page.click('[data-modal-open="block-add"]');

    return page.locator('#block-add');
}

test('adres bloğu: yöntem seçilmeden sonraki alanlar sorulmaz', async ({ page }) => {
    const window = await blockDialog(page);

    // Yöntem seçilmemişken omurga switch ve yönteme özel alanların hiçbiri açık olmamalı.
    await expect(window.locator('[name="core_switch_id"]')).toBeHidden();
    await expect(window.locator('[name="access_switch_id"]')).toBeHidden();
    await expect(window.locator('[name="next_hop_source"]').first()).toBeHidden();
    await expect(window.locator('[name="prefix_list_id"]')).toBeHidden();
});

test('adres bloğu: VLAN seçilince uplink alanları açılmaz', async ({ page }) => {
    const window = await blockDialog(page);

    await window.locator('[name="connection_type"]').selectOption('vlan');

    await expect(window.locator('[name="core_switch_id"]')).toBeVisible();
    await expect(window.locator('[name="access_switch_id"]')).toBeVisible();

    // Asıl hatalar: VLAN yöntemindeyken "Uplink IP" ve "Port" görünüyordu.
    await expect(window.locator('[name="next_hop_switch"]')).toBeHidden();
    await expect(window.locator('[name="next_hop"]')).toBeHidden();
    await expect(window.locator('[name="port_name"]')).toBeHidden();
    await expect(window.locator('[name="prefix_list_id"]')).toBeHidden();
});

test('adres bloğu: static route seçilince kaynak sorulur, IP alanı sonra açılır', async ({ page }) => {
    const window = await blockDialog(page);

    await window.locator('[name="connection_type"]').selectOption('next_hop');

    await expect(window.locator('[name="next_hop_source"]').first()).toBeVisible();
    await expect(window.locator('[name="access_switch_id"]')).toBeHidden();

    // Kaynak seçilmeden IP alanlarının ikisi de kapalı.
    await expect(window.locator('[name="next_hop_switch"]')).toBeHidden();
    await expect(window.locator('[name="next_hop"]')).toBeHidden();

    await window.locator('[name="next_hop_source"][value="manual"]').check();
    await expect(window.locator('[name="next_hop"]')).toBeVisible();
    await expect(window.locator('[name="next_hop_switch"]')).toBeHidden();

    await window.locator('[name="next_hop_source"][value="switch"]').check();
    await expect(window.locator('[name="next_hop_switch"]')).toBeVisible();
    await expect(window.locator('[name="next_hop"]')).toBeHidden();
});

test('adres bloğu: omurgada tiki erişim switchini kaldırır', async ({ page }) => {
    const window = await blockDialog(page);

    await window.locator('[name="connection_type"]').selectOption('vlan');
    await expect(window.locator('[name="access_switch_id"]')).toBeVisible();

    // Tik konunca erişim switchi hiç sorulmaz; hedefler omurgadan gelir.
    await window.locator('[data-assignment-backbone]').check();
    await expect(window.locator('[name="access_switch_id"]')).toBeHidden();

    await window.locator('[data-assignment-backbone]').uncheck();
    await expect(window.locator('[name="access_switch_id"]')).toBeVisible();
});

test('adres bloğu: prefix list omurga seçilene kadar sorulmaz', async ({ page }) => {
    const window = await blockDialog(page);

    await window.locator('[name="connection_type"]').selectOption('prefix_list');

    // Blok görünür ama içindeki tek alan omurga seçilene kadar kapalı; boş
    // blok sıfır yükseklikte olduğu için doğrudan alana bakılır.
    await expect(window.locator('[name="prefix_list_id"]')).toBeHidden();

    const backbone = window.locator('[name="core_switch_id"]');
    await backbone.selectOption({ index: 1 });

    await expect(window.locator('[name="prefix_list_id"]')).toBeVisible();
});

test('port ayarları: trunk seçilince çoklu VLAN listesi açılır', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    await page.locator('a[href*="/switches/"]').first().click();
    await page.locator('a[href$="/ports"]').first().click();

    await page.locator('.grid-row.port-row').first().click();
    await page.locator('[data-port-edit]').click();

    const window = page.locator('.modal:not([hidden])').first();
    const mode = window.locator('[data-port-mode]');

    await mode.selectOption('access');
    await expect(window.locator('[data-port-vlan="access"]')).toBeVisible();
    await expect(window.locator('[data-port-vlan="trunk"]')).toBeHidden();

    // Asıl hata: trunk seçilince birden fazla VLAN seçilemiyordu.
    await mode.selectOption('trunk');
    await expect(window.locator('[data-port-vlan="trunk"]')).toBeVisible();
    await expect(window.locator('[data-port-vlan="access"]')).toBeHidden();
    expect(await window.locator('[data-port-vlan="trunk"] input[type="checkbox"]').count()).toBeGreaterThan(1);
});

/**
 * BGP grup formu: Junos'ta birbirini dışlayan alanlar yalnızca koşulu
 * sağlandığında sorulur. Bu kısıtların hepsi cihazda doğrulandı; sunucu
 * tarafı da aynı kuralları uygular (BgpGroupRequest).
 */
/**
 * BGP'si kurulu bir switche gider.
 *
 * Listedeki ilk switch seçilemez: AS numarası tanımlı olmayan switchte grup
 * formu HİÇ çizilmez, çünkü cihaz AS numarası olmadan BGP'yi kabul etmiyor.
 * Genel bakıştaki bir komşudan gidilirse switchin BGP'si kurulu olduğu kesindir.
 */
async function groupDialog(page) {
    await performLogin(page);
    await page.goto('/bgp');

    const rows = page.locator('a.bgp-row');
    test.skip(await rows.count() === 0, 'envanterde BGP komşusu yok');

    await rows.first().click();
    await page.getByRole('link', { name: 'Switchte yönet' }).click();
    await page.waitForURL(/bgp$/);

    const button = page.locator('[data-modal-open="group-add"]');
    await button.waitFor({ state: 'visible', timeout: 10000 });
    await button.click();

    return page.locator('#group-add');
}

test('bgp grubu: iBGP seçilince karşı taraf AS sorulmaz', async ({ page }) => {
    const window = await groupDialog(page);

    // eBGP'de karşı tarafın AS'i zorunludur.
    await window.locator('[name="type"]').selectOption('external');
    await expect(window.locator('[name="peer_asn"]')).toBeVisible();

    // iBGP'de karşı taraf zaten kendi AS'imizdir.
    await window.locator('[name="type"]').selectOption('internal');
    await expect(window.locator('[name="peer_asn"]')).toBeHidden();
});

test('bgp grubu: multihop kapalıyken TTL sorulmaz', async ({ page }) => {
    const window = await groupDialog(page);

    await expect(window.locator('[name="multihop_ttl"]')).toBeHidden();

    await window.locator('[name="multihop"][type="checkbox"]').check();
    await expect(window.locator('[name="multihop_ttl"]')).toBeVisible();
});

test('bgp grubu: aile açılmadan prefix sınırı sorulmaz', async ({ page }) => {
    const window = await groupDialog(page);

    const limit = window.locator('[name="families[inet][prefix_limit_maximum]"]');
    await expect(limit).toBeHidden();

    await window.locator('[name="families[inet][enabled]"]').check();
    await expect(limit).toBeVisible();
});

test('bgp grubu: süresiz bekleme seçiliyken dakika sorulmaz', async ({ page }) => {
    const window = await groupDialog(page);

    await window.locator('[name="families[inet][enabled]"]').check();

    // Sınır aşılınca düşürme açılmadan eşik ve bekleme sorulmaz.
    const threshold = window.locator('[name="families[inet][teardown_threshold]"]');
    await expect(threshold).toBeHidden();

    await window.locator('[name="families[inet][teardown]"]').check();
    await expect(threshold).toBeVisible();

    const minute = window.locator('[name="families[inet][idle_timeout_minutes]"]');
    await expect(minute).toBeVisible();

    // Junos ikisini birden yazamaz: ya süresiz ya da verilen dakika.
    await window.locator('[name="families[inet][idle_timeout_forever]"]').check();
    await expect(minute).toBeHidden();
});

/**
 * Bağlantı toplama (LAG).
 *
 * ae arayüzleri panelde yaratılmaz; chassis'in device-count kadarını
 * oluşturmasıyla var olurlar. Bu yüzden test, ae'si OLAN bir switch arar.
 */
async function aggregationDialog(page) {
    await performLogin(page);
    await page.goto('/switches');

    const addresses = await page.locator('a.switch-row').evaluateAll(
        (connections) => connections.map((b) => b.getAttribute('href')),
    );

    for (const address of addresses) {
        await page.goto(`${address}/aggregation`);

        // "ae-limit" düğmesi de bu önekle başlıyor; o ayrı bir penceredir.
        const button = page
            .locator('[data-modal-open^="ae-"]:not([data-modal-open="ae-limit"])')
            .first();

        if (await button.count()) {
            await button.click();

            return page.locator('.modal:not([hidden])').first();
        }
    }

    test.skip(true, 'envanterde toplama arayüzü olan switch yok');

    return null;
}

test('ae: LACP seçilmeden periyot sorulmaz', async ({ page }) => {
    const window = await aggregationDialog(page);

    const lacp = window.locator('[name="lacp_mode"]');
    const period = window.locator('[name="lacp_periodic"]');

    await lacp.selectOption('');
    await expect(period).toBeHidden();

    await lacp.selectOption('active');
    await expect(period).toBeVisible();
});

test('ae: access modunda etiketsiz VLAN sorulmaz', async ({ page }) => {
    const window = await aggregationDialog(page);

    const mode = window.locator('[name="port_mode"]');
    const untagged = window.locator('[name="untagged_vlan"]');

    await mode.selectOption('access');
    await expect(untagged).toBeHidden();

    await mode.selectOption('trunk');
    await expect(untagged).toBeVisible();
});

/**
 * Oturum configi penceresi: karşı tarafın ve bizim tarafın metni yan yana.
 * İkisi de configure modunda yapıştırılabilir olmalı, yani blokta yalnızca
 * komut bulunmalı. Bizim tarafın metninde sır bulunmamalı.
 */
test('oturum configi iki tarafı da verir ve yalnızca komut içerir', async ({ page }) => {
    await performLogin(page);
    await page.goto('/bgp');

    const rows = page.locator('a.bgp-row');
    test.skip(await rows.count() === 0, 'envanterde BGP komşusu yok');

    await rows.first().click();
    await page.getByRole('link', { name: 'Switchte yönet' }).click();
    await page.waitForURL(/bgp$/);

    await page.locator('[data-modal-open^="peer-"]').first().click();

    const window = page.locator('.modal:not([hidden])').first();
    await expect(window).toBeVisible();

    const opposite = await window.locator('[id^="peer-config-"]').inputValue();
    const ours = await window.locator('[id^="our-config-"]').inputValue();

    for (const text of [opposite, ours]) {
        expect(text).toContain('set protocols bgp group');
        text.split('\n').filter(Boolean).forEach((row) => {
            expect(row.startsWith('set ')).toBe(true);
        });
    }

    // Cihazdan okunan "$9$..." biçimi çözülebilir; ekrana basılmamalı.
    expect(ours).not.toContain('$9$');

    await expect(window.getByRole('button', { name: 'Karşı tarafı kopyala' })).toBeVisible();
    await expect(window.getByRole('button', { name: 'Bizim tarafı kopyala' })).toBeVisible();
});

/**
 * Firewall filtresi: koşullar AİLEYE göre değişir.
 *
 * Junos'ta "next-header" yalnızca IPv6'da, "ether-type" ve MAC adresleri
 * yalnızca ethernet-switching'de, IPv4 başlık alanları (ip-options, parça
 * bayrakları) yalnızca inet'te bulunur. Yanlış ailede gönderilen koşulu cihaz
 * reddeder; panel onu hiç sormaz.
 *
 * Bu sınıf hata sunucu testlerinden GEÇER: alanlar HTML'de vardır, yalnızca
 * yanlış anda görünürler.
 */
async function filterDialog(page) {
    await performLogin(page);
    await page.goto('/switches');
    await page.locator('a[href*="/switches/"]').first().click();
    await page.locator('a[href$="/firewall"]').first().click();

    const button = page.locator('[data-modal-open="filter-add"]');
    test.skip(await button.count() === 0, 'firewall yazma yetkisi yok');

    await button.click();

    return page.locator('#filter-add');
}

test('firewall filtresi: aile değişince o aileye ait olmayan koşullar sorulmaz', async ({ page }) => {
    const window = await filterDialog(page);
    const family = window.locator('[name="family"]');

    // IPv4: parça bayrakları var, next-header ve MAC yok.
    await family.selectOption('inet');
    await expect(window.locator('[name="is_fragment"][type="checkbox"]')).toBeVisible();
    await expect(window.locator('[name="next_headers[]"]').first()).toBeHidden();
    await expect(window.locator('[name="source_mac_addresses"]')).toBeHidden();

    // IPv6: next-header ve trafik sınıfı açılır, IPv4 başlık alanları kapanır.
    await family.selectOption('inet6');
    await expect(window.locator('[name="next_headers[]"]').first()).toBeVisible();
    await expect(window.locator('[name="traffic_classes"]')).toBeVisible();
    await expect(window.locator('[name="is_fragment"][type="checkbox"]')).toBeHidden();

    // ethernet-switching: yalnızca ether-type ve MAC alanları açılır.
    await family.selectOption('ethernet-switching');
    await expect(window.locator('[name="source_mac_addresses"]')).toBeVisible();
    await expect(window.locator('[name="next_headers[]"]').first()).toBeHidden();
    await expect(window.locator('[name="is_fragment"][type="checkbox"]')).toBeHidden();
});
