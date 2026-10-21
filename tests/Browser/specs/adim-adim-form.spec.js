import { expect, test } from '@playwright/test';

import { girisYap } from '../support/giris.js';

/**
 * Formların adım adım açıldığını doğrular.
 *
 * Bu hatalar sunucu testlerinden geçiyor: alanlar HTML'de var, yalnızca
 * yanlış anda görünüyorlar. VLAN yöntemi seçiliyken "Uplink IP" alanının
 * açık kalması ve port modunda trunk seçiminin çalışmaması böyle kaçtı.
 */

async function blokPenceresi(page) {
    await girisYap(page);
    await page.goto('/adresleme');
    await page.click('[data-modal-open="blok-ekle"]');

    return page.locator('#blok-ekle');
}

test('adres bloğu: yöntem seçilmeden sonraki alanlar sorulmaz', async ({ page }) => {
    const pencere = await blokPenceresi(page);

    // Yöntem seçilmemişken omurga switch ve yönteme özel alanların hiçbiri açık olmamalı.
    await expect(pencere.locator('[name="core_switch_id"]')).toBeHidden();
    await expect(pencere.locator('[name="access_switch_id"]')).toBeHidden();
    await expect(pencere.locator('[name="next_hop_source"]').first()).toBeHidden();
    await expect(pencere.locator('[name="prefix_list_id"]')).toBeHidden();
});

test('adres bloğu: VLAN seçilince uplink alanları açılmaz', async ({ page }) => {
    const pencere = await blokPenceresi(page);

    await pencere.locator('[name="connection_type"]').selectOption('vlan');

    await expect(pencere.locator('[name="core_switch_id"]')).toBeVisible();
    await expect(pencere.locator('[name="access_switch_id"]')).toBeVisible();

    // Asıl hatalar: VLAN yöntemindeyken "Uplink IP" ve "Port" görünüyordu.
    await expect(pencere.locator('[name="next_hop_switch"]')).toBeHidden();
    await expect(pencere.locator('[name="next_hop"]')).toBeHidden();
    await expect(pencere.locator('[name="port_name"]')).toBeHidden();
    await expect(pencere.locator('[name="prefix_list_id"]')).toBeHidden();
});

test('adres bloğu: static route seçilince kaynak sorulur, IP alanı sonra açılır', async ({ page }) => {
    const pencere = await blokPenceresi(page);

    await pencere.locator('[name="connection_type"]').selectOption('next_hop');

    await expect(pencere.locator('[name="next_hop_source"]').first()).toBeVisible();
    await expect(pencere.locator('[name="access_switch_id"]')).toBeHidden();

    // Kaynak seçilmeden IP alanlarının ikisi de kapalı.
    await expect(pencere.locator('[name="next_hop_switch"]')).toBeHidden();
    await expect(pencere.locator('[name="next_hop"]')).toBeHidden();

    await pencere.locator('[name="next_hop_source"][value="manual"]').check();
    await expect(pencere.locator('[name="next_hop"]')).toBeVisible();
    await expect(pencere.locator('[name="next_hop_switch"]')).toBeHidden();

    await pencere.locator('[name="next_hop_source"][value="switch"]').check();
    await expect(pencere.locator('[name="next_hop_switch"]')).toBeVisible();
    await expect(pencere.locator('[name="next_hop"]')).toBeHidden();
});

test('adres bloğu: omurgada tiki erişim switchini kaldırır', async ({ page }) => {
    const pencere = await blokPenceresi(page);

    await pencere.locator('[name="connection_type"]').selectOption('vlan');
    await expect(pencere.locator('[name="access_switch_id"]')).toBeVisible();

    // Tik konunca erişim switchi hiç sorulmaz; hedefler omurgadan gelir.
    await pencere.locator('[data-atama-omurgada]').check();
    await expect(pencere.locator('[name="access_switch_id"]')).toBeHidden();

    await pencere.locator('[data-atama-omurgada]').uncheck();
    await expect(pencere.locator('[name="access_switch_id"]')).toBeVisible();
});

test('adres bloğu: prefix list omurga seçilene kadar sorulmaz', async ({ page }) => {
    const pencere = await blokPenceresi(page);

    await pencere.locator('[name="connection_type"]').selectOption('prefix_list');

    // Blok görünür ama içindeki tek alan omurga seçilene kadar kapalı; boş
    // blok sıfır yükseklikte olduğu için doğrudan alana bakılır.
    await expect(pencere.locator('[name="prefix_list_id"]')).toBeHidden();

    const omurga = pencere.locator('[name="core_switch_id"]');
    await omurga.selectOption({ index: 1 });

    await expect(pencere.locator('[name="prefix_list_id"]')).toBeVisible();
});

test('port ayarları: trunk seçilince çoklu VLAN listesi açılır', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    await page.locator('a[href*="/switchler/"]').first().click();
    await page.locator('a[href$="/portlar"]').first().click();

    await page.locator('.grid-row.port-row').first().click();
    await page.locator('[data-port-duzenle]').click();

    const pencere = page.locator('.modal:not([hidden])').first();
    const mod = pencere.locator('[data-port-mode]');

    await mod.selectOption('access');
    await expect(pencere.locator('[data-port-vlan="access"]')).toBeVisible();
    await expect(pencere.locator('[data-port-vlan="trunk"]')).toBeHidden();

    // Asıl hata: trunk seçilince birden fazla VLAN seçilemiyordu.
    await mod.selectOption('trunk');
    await expect(pencere.locator('[data-port-vlan="trunk"]')).toBeVisible();
    await expect(pencere.locator('[data-port-vlan="access"]')).toBeHidden();
    expect(await pencere.locator('[data-port-vlan="trunk"] input[type="checkbox"]').count()).toBeGreaterThan(1);
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
async function grupPenceresi(page) {
    await girisYap(page);
    await page.goto('/bgp');

    const satirlar = page.locator('a.bgp-row');
    test.skip(await satirlar.count() === 0, 'envanterde BGP komşusu yok');

    await satirlar.first().click();
    await page.getByRole('link', { name: 'Switchte yönet' }).click();
    await page.waitForURL(/bgp$/);

    const dugme = page.locator('[data-modal-open="grup-ekle"]');
    await dugme.waitFor({ state: 'visible', timeout: 10000 });
    await dugme.click();

    return page.locator('#grup-ekle');
}

test('bgp grubu: iBGP seçilince karşı taraf AS sorulmaz', async ({ page }) => {
    const pencere = await grupPenceresi(page);

    // eBGP'de karşı tarafın AS'i zorunludur.
    await pencere.locator('[name="type"]').selectOption('external');
    await expect(pencere.locator('[name="peer_asn"]')).toBeVisible();

    // iBGP'de karşı taraf zaten kendi AS'imizdir.
    await pencere.locator('[name="type"]').selectOption('internal');
    await expect(pencere.locator('[name="peer_asn"]')).toBeHidden();
});

test('bgp grubu: multihop kapalıyken TTL sorulmaz', async ({ page }) => {
    const pencere = await grupPenceresi(page);

    await expect(pencere.locator('[name="multihop_ttl"]')).toBeHidden();

    await pencere.locator('[name="multihop"][type="checkbox"]').check();
    await expect(pencere.locator('[name="multihop_ttl"]')).toBeVisible();
});

test('bgp grubu: aile açılmadan prefix sınırı sorulmaz', async ({ page }) => {
    const pencere = await grupPenceresi(page);

    const sinir = pencere.locator('[name="families[inet][prefix_limit_maximum]"]');
    await expect(sinir).toBeHidden();

    await pencere.locator('[name="families[inet][enabled]"]').check();
    await expect(sinir).toBeVisible();
});

test('bgp grubu: süresiz bekleme seçiliyken dakika sorulmaz', async ({ page }) => {
    const pencere = await grupPenceresi(page);

    await pencere.locator('[name="families[inet][enabled]"]').check();

    // Sınır aşılınca düşürme açılmadan eşik ve bekleme sorulmaz.
    const esik = pencere.locator('[name="families[inet][teardown_threshold]"]');
    await expect(esik).toBeHidden();

    await pencere.locator('[name="families[inet][teardown]"]').check();
    await expect(esik).toBeVisible();

    const dakika = pencere.locator('[name="families[inet][idle_timeout_minutes]"]');
    await expect(dakika).toBeVisible();

    // Junos ikisini birden yazamaz: ya süresiz ya da verilen dakika.
    await pencere.locator('[name="families[inet][idle_timeout_forever]"]').check();
    await expect(dakika).toBeHidden();
});

/**
 * Bağlantı toplama (LAG).
 *
 * ae arayüzleri panelde yaratılmaz; chassis'in device-count kadarını
 * oluşturmasıyla var olurlar. Bu yüzden test, ae'si OLAN bir switch arar.
 */
async function toplamaPenceresi(page) {
    await girisYap(page);
    await page.goto('/switchler');

    const adresler = await page.locator('a.switch-row').evaluateAll(
        (baglantilar) => baglantilar.map((b) => b.getAttribute('href')),
    );

    for (const adres of adresler) {
        await page.goto(`${adres}/toplama`);

        // "ae-limiti" düğmesi de bu önekle başlıyor; o ayrı bir penceredir.
        const dugme = page
            .locator('[data-modal-open^="ae-"]:not([data-modal-open="ae-limiti"])')
            .first();

        if (await dugme.count()) {
            await dugme.click();

            return page.locator('.modal:not([hidden])').first();
        }
    }

    test.skip(true, 'envanterde toplama arayüzü olan switch yok');

    return null;
}

test('ae: LACP seçilmeden periyot sorulmaz', async ({ page }) => {
    const pencere = await toplamaPenceresi(page);

    const lacp = pencere.locator('[name="lacp_mode"]');
    const periyot = pencere.locator('[name="lacp_periodic"]');

    await lacp.selectOption('');
    await expect(periyot).toBeHidden();

    await lacp.selectOption('active');
    await expect(periyot).toBeVisible();
});

test('ae: access modunda etiketsiz VLAN sorulmaz', async ({ page }) => {
    const pencere = await toplamaPenceresi(page);

    const mod = pencere.locator('[name="port_mode"]');
    const etiketsiz = pencere.locator('[name="untagged_vlan"]');

    await mod.selectOption('access');
    await expect(etiketsiz).toBeHidden();

    await mod.selectOption('trunk');
    await expect(etiketsiz).toBeVisible();
});

/**
 * Oturum configi penceresi: karşı tarafın ve bizim tarafın metni yan yana.
 * İkisi de configure modunda yapıştırılabilir olmalı, yani blokta yalnızca
 * komut bulunmalı. Bizim tarafın metninde sır bulunmamalı.
 */
test('oturum configi iki tarafı da verir ve yalnızca komut içerir', async ({ page }) => {
    await girisYap(page);
    await page.goto('/bgp');

    const satirlar = page.locator('a.bgp-row');
    test.skip(await satirlar.count() === 0, 'envanterde BGP komşusu yok');

    await satirlar.first().click();
    await page.getByRole('link', { name: 'Switchte yönet' }).click();
    await page.waitForURL(/bgp$/);

    await page.locator('[data-modal-open^="peer-"]').first().click();

    const pencere = page.locator('.modal:not([hidden])').first();
    await expect(pencere).toBeVisible();

    const karsi = await pencere.locator('[id^="peer-metin-"]').inputValue();
    const bizim = await pencere.locator('[id^="bizim-metin-"]').inputValue();

    for (const metin of [karsi, bizim]) {
        expect(metin).toContain('set protocols bgp group');
        metin.split('\n').filter(Boolean).forEach((satir) => {
            expect(satir.startsWith('set ')).toBe(true);
        });
    }

    // Cihazdan okunan "$9$..." biçimi çözülebilir; ekrana basılmamalı.
    expect(bizim).not.toContain('$9$');

    await expect(pencere.getByRole('button', { name: 'Karşı tarafı kopyala' })).toBeVisible();
    await expect(pencere.getByRole('button', { name: 'Bizim tarafı kopyala' })).toBeVisible();
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
async function filtrePenceresi(page) {
    await girisYap(page);
    await page.goto('/switchler');
    await page.locator('a[href*="/switchler/"]').first().click();
    await page.locator('a[href$="/firewall"]').first().click();

    const dugme = page.locator('[data-modal-open="filtre-ekle"]');
    test.skip(await dugme.count() === 0, 'firewall yazma yetkisi yok');

    await dugme.click();

    return page.locator('#filtre-ekle');
}

test('firewall filtresi: aile değişince o aileye ait olmayan koşullar sorulmaz', async ({ page }) => {
    const pencere = await filtrePenceresi(page);
    const aile = pencere.locator('[name="family"]');

    // IPv4: parça bayrakları var, next-header ve MAC yok.
    await aile.selectOption('inet');
    await expect(pencere.locator('[name="is_fragment"][type="checkbox"]')).toBeVisible();
    await expect(pencere.locator('[name="next_headers[]"]').first()).toBeHidden();
    await expect(pencere.locator('[name="source_mac_addresses"]')).toBeHidden();

    // IPv6: next-header ve trafik sınıfı açılır, IPv4 başlık alanları kapanır.
    await aile.selectOption('inet6');
    await expect(pencere.locator('[name="next_headers[]"]').first()).toBeVisible();
    await expect(pencere.locator('[name="traffic_classes"]')).toBeVisible();
    await expect(pencere.locator('[name="is_fragment"][type="checkbox"]')).toBeHidden();

    // ethernet-switching: yalnızca ether-type ve MAC alanları açılır.
    await aile.selectOption('ethernet-switching');
    await expect(pencere.locator('[name="source_mac_addresses"]')).toBeVisible();
    await expect(pencere.locator('[name="next_headers[]"]').first()).toBeHidden();
    await expect(pencere.locator('[name="is_fragment"][type="checkbox"]')).toBeHidden();
});
