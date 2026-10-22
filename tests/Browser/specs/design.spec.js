import { expect, test } from '@playwright/test';

import { performLogin } from '../support/login.js';

/**
 * Tasarım tutarlılığı.
 *
 * Kural: tasarım standart ölçüler, yerleşimler ve yapılar üzerinden
 * kurulur; bir sayfanın ölçüsü, kartı ya da yerleşimi diğerlerinden
 * ayrışmaz.
 *
 * Bu dosya görünüşü BEĞENMEZ, ÖLÇER. Ekran görüntüsü karşılaştırmak yerine
 * sayısal tutarlılık sınanır; böylece içerik değişince test kırılmaz ama
 * biri yeni bir ölçü icat edince kırılır.
 *
 * Üç genişlikte de çalışır: masaüstü, tablet, mobil.
 */

async function pages(page) {
    await page.goto('/switches');
    const first = await page.locator('a.switch-row').first().getAttribute('href');

    return [
        '/switches', first, `${first}/ports`, `${first}/vlans`, `${first}/firewall`,
        `${first}/policies`, '/addressing', '/servers', '/users',
    ];
}

/** Bir CSS ölçüsünü piksele çevirir; "12px" -> 12. */
const px = (value) => Math.round(parseFloat(value) || 0);

test.describe('tasarım', () => {
    /**
     * Aynı sütundaki kartlar aynı genişlikte durur.
     *
     * "Bu kart biraz dar olsun" bir sayfayı bütün panelden ayırır ve
     * gözle fark edilmesi en zor tutarsızlıktır.
     */
    test('kartlar aynı sütunda aynı genişlikte', async ({ page }) => {
        await performLogin(page);

        for (const address of await pages(page)) {
            await page.goto(address);

            const widths = await page.locator('.panel:visible').evaluateAll(
                (cards) => cards.map((k) => Math.round(k.getBoundingClientRect().width)),
            );

            if (widths.length < 2) {
                continue;
            }

            const unique = [...new Set(widths)];

            expect(unique, `${address} sayfasında kart genişlikleri ayrışıyor: ${widths.join(', ')}`)
                .toHaveLength(1);
        }
    });

    /**
     * Düğmeler serbest yükseklik almaz: "btn" ve boy sınıfları dışında bir
     * yükseklik üretilmemeli.
     */
    test('düğme yükseklikleri sınırlı bir kümeden gelir', async ({ page }) => {
        await performLogin(page);

        const heights = new Set();

        for (const address of await pages(page)) {
            await page.goto(address);

            const measures = await page.locator('.btn:visible').evaluateAll(
                (buttons) => buttons.map((d) => Math.round(d.getBoundingClientRect().height)),
            );

            measures.forEach((h) => h > 0 && heights.add(h));
        }

        expect([...heights].sort((a, b) => a - b).join(', '))
            .toMatch(/^[\d, ]+$/);

        // Üç boy sınıfı var: normal, sm, icon. Dörtten fazlası icat demektir.
        expect(heights.size, `düğme yüksekliği çeşidi: ${[...heights].join(', ')}`)
            .toBeLessThanOrEqual(4);
    });

    /**
     * Boşluklar değişkenlerden gelir. Serbest değer (13px, 17px gibi) yazan
     * bir ekran bütün panelden ayrışır.
     */
    test('kart iç boşlukları tek değerde', async ({ page }) => {
        await performLogin(page);

        const gaps = new Set();

        for (const address of await pages(page)) {
            await page.goto(address);

            const measures = await page.locator('.panel:visible').evaluateAll(
                (cards) => cards.map((k) => getComputedStyle(k).paddingLeft),
            );

            measures.forEach((p) => gaps.add(px(p)));
        }

        expect(gaps.size, `kart iç boşluğu çeşidi: ${[...gaps].join(', ')}`)
            .toBe(1);
    });

    /**
     * Form alanları aynı dikey ritmi paylaşır: alanlar arası boşluk tek
     * değerde olmalı, yoksa aynı pencerede alanlar farklı sıklıkta dizilir.
     */
    test('form alanları aynı dikey ritmi paylaşır', async ({ page }) => {
        await performLogin(page);
        await page.goto('/switches');

        const first = await page.locator('a.switch-row').first().getAttribute('href');
        await page.goto(`${first}/ports`);

        const grids = await page.locator('.form-grid').evaluateAll(
            (boxes) => boxes.map((k) => getComputedStyle(k).rowGap),
        );

        const unique = [...new Set(grids.map((g) => Math.round(parseFloat(g) || 0)))];

        expect(unique.length, `form ızgarası satır boşluğu çeşidi: ${unique.join(', ')}`)
            .toBeLessThanOrEqual(1);
    });

    /**
     * Üst çubuk her ekranda aynı yükseklikte durur; içine eklenen bir düğme
     * (ör. ayrıntı düzeyi) onu büyütmemeli.
     */
    test('üst çubuk her sayfada aynı yükseklikte', async ({ page }) => {
        await performLogin(page);

        const heights = new Set();

        for (const address of await pages(page)) {
            await page.goto(address);

            heights.add(await page.locator('.topbar').evaluate(
                (c) => Math.round(c.getBoundingClientRect().height),
            ));
        }

        expect(heights.size, `üst çubuk yüksekliği değişiyor: ${[...heights].join(', ')}`)
            .toBe(1);
    });

    /**
     * HİÇBİR sayfa yatay kaymaz — üç genişlikte de.
     *
     * Tek bir bozuk seçici listesi tablet genişliğinde PANELİN HER SAYFASINI
     * yatay kaydırabilir: `flex-shrink: 0` bölüm menüsüne de uygulanırsa menü
     * daralmaz, üst çubuk satırı ekrandan taşar ve üst çubuk sabit olduğu
     * için taşma bütün belgeye geçer.
     *
     * Ölçü sayfaya değil ÇERÇEVEYE aittir: bir sayfada kırılırsa hepsinde
     * kırılır, bu yüzden her sayfa tek tek denenir.
     */
    test('hiçbir sayfa yatay kaymaz', async ({ page }) => {
        await performLogin(page);

        const overflowing = [];

        for (const address of await pages(page)) {
            await page.goto(address);

            const overflow = await page.evaluate(
                () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
            );

            // Yuvarlama payı: 1 piksel taşma göze görünmez, 2 piksel kayar.
            if (overflow > 1) {
                overflowing.push(`${address} (+${overflow}px)`);
            }
        }

        expect(overflowing, `yatay kayan sayfalar: ${overflowing.join(', ')}`).toEqual([]);
    });

    /**
     * Üst çubuk sayfa içeriğinin ÜSTÜNDE bir katman kurar.
     *
     * Bir kez kurmadı ve içinden açılan bildirim paneli yapışkan tablo
     * başlığının ALTINDA kaldı: ikisi de aynı z-index'i kullanıyordu ve eşit
     * değerde DOM'da sonra gelen kazanıyor.
     *
     * "O noktada hangi öğe var" diye sormak İŞE YARAMADI: panelin yüksekliği
     * içeriğine bağlı ve bildirimi olmayan bir kullanıcıda panel içeriğe hiç
     * değmiyor — test veriye bağlı hâle gelirdi. Ölçülen şey bu yüzden
     * KATMANIN KENDİSİ: üst çubuk bir yığılma bağlamı kurmalı ve değeri
     * yapışkan içeriğinkinden büyük olmalı.
     */
    test('üst çubuk yapışkan içeriğin üstünde bir katman kurar', async ({ page }) => {
        await performLogin(page);
        await page.goto('/servers');

        const layers = await page.evaluate(() => {
            const number = (el) => {
                const z = el ? getComputedStyle(el).zIndex : 'auto';

                return z === 'auto' ? null : Number(z);
            };

            const sticky = [...document.querySelectorAll('*')].find(
                (el) => getComputedStyle(el).position === 'sticky' && number(el) !== null,
            );

            return {
                topbar: number(document.querySelector('.topbar')),
                sticky: number(sticky),
                stickyName: sticky?.className ?? '(yok)',
            };
        });

        expect(layers.topbar, 'üst çubuk yığılma bağlamı kurmuyor (z-index: auto)')
            .not.toBeNull();

        if (layers.sticky !== null) {
            expect(
                layers.topbar,
                `üst çubuk (${layers.topbar}) yapışkan içeriğin (${layers.stickyName}: ${layers.sticky}) altında`,
            ).toBeGreaterThan(layers.sticky);
        }
    });
});
