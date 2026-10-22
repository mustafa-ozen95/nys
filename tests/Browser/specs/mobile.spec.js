import { expect, test } from '@playwright/test';

import { performLogin } from '../support/login.js';

/**
 * Mobil uyum.
 *
 * Bu ekranların hepsi sütunlu bir şerittir ve telefonda yatay kayar. İki
 * şey sınanır: sayfanın kendisi yatay kaymamalı (yani hiçbir şey belgeyi
 * genişletmemeli) ve açılışta İÇERİK sütununda olunmalı — kullanıcı aradığı
 * şeye ulaşmak için önce gezinme sütunlarını geçmek zorunda kalmamalı.
 */

test.describe('mobil', () => {
    // Bu kurallar yalnızca telefon genişliğinde geçerli.
    test.beforeEach(({ }, testInfo) => {
        test.skip(testInfo.project.name !== 'mobil', 'yalnızca telefon genişliğinde');
    });

    test('sayfalar yatay kaymaz', async ({ page }) => {
        await performLogin(page);
        await page.goto('/switches');

        const first = await page.locator('a.switch-row').first().getAttribute('href');

        const addresses = [
            '/switches', first, `${first}/ports`, `${first}/aggregation`, `${first}/firewall`,
            `${first}/policies`, `${first}/bgp`, '/bgp', '/addressing', '/servers',

            // Yönetim ekranları da listede: kayıtlar sayfası sayfalama şeridi
            // yüzünden taşıyordu ve test onu hiç gezmiyordu.
            '/users', '/logs', '/settings', '/settings/passwords', '/config', '/prefix-announcement',
        ];

        for (const address of addresses) {
            await page.goto(address);
            await page.waitForLoadState('networkidle');

            const overflow = await page.evaluate(
                () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
            );

            expect(overflow, `${address} yatay kayıyor`).toBeLessThanOrEqual(1);
        }
    });

    /**
     * Bir parmak hareketi bir bölme ilerletmeli.
     *
     * Bölmeler ekrandan darsa tek geçiş birkaç hareket sürer ve kaydırma
     * bölmenin ortasında kalır.
     */
    test('her bölme tam ekran genişliğinde', async ({ page }) => {
        await performLogin(page);
        await page.goto('/switches');

        const first = await page.locator('a.switch-row').first().getAttribute('href');
        await page.goto(`${first}/ports`);
        await page.waitForLoadState('networkidle');

        const measurement = await page.evaluate(() => ({
            /*
             * Yalnızca GÖRÜNEN bölmeler ölçülür: bazı ekranlarda detay paneli
             * hiç çizilmez (display: none) ve genişliği sıfırdır.
             */
            widths: [...document.querySelectorAll('.columns > .column, .columns > .detail')]
                .filter((el) => getComputedStyle(el).display !== 'none')
                .map((el) => Math.round(el.getBoundingClientRect().width)),
            screen: document.documentElement.clientWidth,
        }));

        expect(measurement.widths.length).toBeGreaterThan(1);

        for (const width of measurement.widths) {
            expect(width).toBeGreaterThanOrEqual(measurement.screen - 2);
        }
    });

    /**
     * Kural her ekranda AYNI olmalı: şerit, kullanıcının indiği en derin
     * bölmeyi açar.
     *
     * Bir kayıt seçiliyse detay paneli en sağdadır ve o açılır; seçim yoksa
     * liste/içerik en sağdadır. "İçerik sütununa in" gibi bir kural ekrana
     * göre değişiyordu: BGP'de detay paneli içerik sütunundan sonra geldiği
     * için seçilen komşunun bilgileri açılmıyordu.
     */
    test('her ekranda en sağdaki bölme açılır', async ({ page }) => {
        await performLogin(page);
        await page.goto('/switches');

        const sw = await page.locator('a.switch-row').first().getAttribute('href');

        const measure = () => page.evaluate(() => {
            const strip = document.querySelector('.columns');
            const segments = [...strip.querySelectorAll(':scope > .column, :scope > .detail')]
                .filter((b) => getComputedStyle(b).display !== 'none');
            const last = segments[segments.length - 1];

            return Math.abs(strip.scrollLeft - (last.offsetLeft - strip.offsetLeft));
        });

        // Seçim yapılan ekranlar: seçimden sonra DETAY açılmalı.
        for (const [address, selector] of [
            ['/bgp', 'a.bgp-row'],
            ['/servers', 'a.grid-row'],
            // Portlarda seçim sayfa yenilemeden yapılır; kural yine geçerli.
            [`${sw}/ports`, '.grid-row.port-row'],
        ]) {
            await page.goto(address);

            const row = page.locator(selector).first();

            if (await row.count() === 0) {
                continue;
            }

            await row.click();
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(400);

            expect(await measure(), `${address} seçimden sonra detayı açmıyor`).toBeLessThanOrEqual(2);
        }

        // Seçim olmayan ekranlar: içerik/liste açılmalı.
        for (const address of [`${sw}/firewall`, sw, '/switches']) {
            await page.goto(address);
            await page.waitForLoadState('networkidle');

            expect(await measure(), `${address} en sağdaki bölmeyi açmıyor`).toBeLessThanOrEqual(2);
        }
    });

    /**
     * Üst menüde "Prefix anonsu" iki satıra düşüyor, "Kullanıcılar" ortadan
     * kesiliyordu; şerit kayar, yazı bölünmez.
     */
    test('üst menü bağlantıları tek satırda kalır', async ({ page }) => {
        await performLogin(page);
        await page.goto('/switches');

        const heights = await page.locator('.topbar__link').evaluateAll(
            (connections) => connections.map((b) => Math.round(b.getBoundingClientRect().height)),
        );

        // Hepsi aynı yükseklikte olmalı: biri iki satıra düşerse ayrışır.
        expect(new Set(heights).size).toBe(1);
    });

    /**
     * Panel içindeki satırlar telefonda yığılır; ad ile açıklama yan yana
     * sıkışıp okunmaz hâle gelmemeli.
     */
    test('panel satırları yığılır', async ({ page }) => {
        await performLogin(page);
        await page.goto('/switches');

        const first = await page.locator('a.switch-row').first().getAttribute('href');
        await page.goto(`${first}/firewall`);

        const row = page.locator('.panel__body .row--between').first();
        test.skip(await row.count() === 0, 'ekranda panel satırı yok');

        const boxes = await row.locator('> *').evaluateAll(
            (children) => children.map((c) => Math.round(c.getBoundingClientRect().top)),
        );

        // En az iki farklı satır olmalı: hepsi aynı hizadaysa yığılmamış demektir.
        expect(new Set(boxes).size).toBeGreaterThan(1);
    });

    /**
     * Tablo hücreleri telefonda etiketsiz kalmamalı.
     *
     * Dar ekranda tablo bloklara dönüşür ve sütun başlıkları kaybolur;
     * etiket data-label ile hücrenin önüne iner. Yetki ekranında bu eksikti
     * ve kullanıcı ekranda altı tane adsız "Devral" kutusu görüyordu —
     * hangisinin "Bağlan" olduğu anlaşılmıyordu.
     */
    test('tablo hücreleri etiketli', async ({ page }) => {
        await performLogin(page);
        await page.goto('/users');
        await page.locator('a[href*="/permissions"]').first().click();
        await page.waitForURL(/permissions/);

        const cells = page.locator('.table tbody td');
        test.skip(await cells.count() === 0, 'ekranda tablo yok');

        const tags = await cells.evaluateAll((list) => list.map(
            (cell) => getComputedStyle(cell, '::before').content,
        ));

        // Hiçbir hücre boş etiketle kalmamalı.
        expect(tags.filter((e) => !e || e === 'none' || e === '""')).toHaveLength(0);
    });

    /**
     * Alana odaklanınca sayfa yakınlaşmamalı.
     *
     * iOS Safari, yazı boyutu 16 pikselin altında olan alanda sayfayı
     * kendiliğinden büyütür ve geri almaz. Eşiği geçmek tek doğru çözümdür;
     * viewport'a "user-scalable=no" yazmak parmakla büyütmeyi de öldürür.
     */
    test('form alanları yakınlaştırma eşiğinin üstünde', async ({ page }) => {
        await performLogin(page);

        // Farklı alan türlerinin geçtiği ekranlar: ayar formu, config metni,
        // yetki matrisindeki dar seçim kutuları, kayıt filtreleri.
        const addresses = ['/settings/device', '/config', '/logs'];

        await page.goto('/users');
        const permission = await page.locator('a[href*="/permissions"]').first().getAttribute('href');

        if (permission) {
            addresses.push(permission);
        }

        for (const address of addresses) {
            await page.goto(address);
            await check(page, address);
        }
    });

    async function check(page, address) {
        const sizes = await page.locator('input, select, textarea').evaluateAll(
            (fields) => fields
                .filter((field) => field.type !== 'hidden' && field.type !== 'checkbox')
                .map((field) => ({
                    ad: field.name || field.id || field.tagName,
                    size: parseFloat(getComputedStyle(field).fontSize),
                })),
        );

        const smaller = sizes.filter((field) => field.size < 16);

        expect(smaller, `${address} → 16px altında alan var: ${JSON.stringify(smaller)}`).toHaveLength(0);
    }
});
