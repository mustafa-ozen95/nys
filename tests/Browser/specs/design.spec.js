import { expect, test } from '@playwright/test';

import { girisYap } from '../support/login.js';

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

async function sayfalar(page) {
    await page.goto('/switchler');
    const ilk = await page.locator('a.switch-row').first().getAttribute('href');

    return [
        '/switchler', ilk, `${ilk}/portlar`, `${ilk}/vlanlar`, `${ilk}/firewall`,
        `${ilk}/politikalar`, '/adresleme', '/sunucular', '/kullanicilar',
    ];
}

/** Bir CSS ölçüsünü piksele çevirir; "12px" -> 12. */
const px = (deger) => Math.round(parseFloat(deger) || 0);

test.describe('tasarım', () => {
    /**
     * Aynı sütundaki kartlar aynı genişlikte durur.
     *
     * "Bu kart biraz dar olsun" bir sayfayı bütün panelden ayırır ve
     * gözle fark edilmesi en zor tutarsızlıktır.
     */
    test('kartlar aynı sütunda aynı genişlikte', async ({ page }) => {
        await girisYap(page);

        for (const adres of await sayfalar(page)) {
            await page.goto(adres);

            const genislikler = await page.locator('.panel:visible').evaluateAll(
                (kartlar) => kartlar.map((k) => Math.round(k.getBoundingClientRect().width)),
            );

            if (genislikler.length < 2) {
                continue;
            }

            const benzersiz = [...new Set(genislikler)];

            expect(benzersiz, `${adres} sayfasında kart genişlikleri ayrışıyor: ${genislikler.join(', ')}`)
                .toHaveLength(1);
        }
    });

    /**
     * Düğmeler serbest yükseklik almaz: "btn" ve boy sınıfları dışında bir
     * yükseklik üretilmemeli.
     */
    test('düğme yükseklikleri sınırlı bir kümeden gelir', async ({ page }) => {
        await girisYap(page);

        const yukseklikler = new Set();

        for (const adres of await sayfalar(page)) {
            await page.goto(adres);

            const olculer = await page.locator('.btn:visible').evaluateAll(
                (dugmeler) => dugmeler.map((d) => Math.round(d.getBoundingClientRect().height)),
            );

            olculer.forEach((h) => h > 0 && yukseklikler.add(h));
        }

        expect([...yukseklikler].sort((a, b) => a - b).join(', '))
            .toMatch(/^[\d, ]+$/);

        // Üç boy sınıfı var: normal, sm, icon. Dörtten fazlası icat demektir.
        expect(yukseklikler.size, `düğme yüksekliği çeşidi: ${[...yukseklikler].join(', ')}`)
            .toBeLessThanOrEqual(4);
    });

    /**
     * Boşluklar değişkenlerden gelir. Serbest değer (13px, 17px gibi) yazan
     * bir ekran bütün panelden ayrışır.
     */
    test('kart iç boşlukları tek değerde', async ({ page }) => {
        await girisYap(page);

        const bosluklar = new Set();

        for (const adres of await sayfalar(page)) {
            await page.goto(adres);

            const olculer = await page.locator('.panel:visible').evaluateAll(
                (kartlar) => kartlar.map((k) => getComputedStyle(k).paddingLeft),
            );

            olculer.forEach((p) => bosluklar.add(px(p)));
        }

        expect(bosluklar.size, `kart iç boşluğu çeşidi: ${[...bosluklar].join(', ')}`)
            .toBe(1);
    });

    /**
     * Form alanları aynı dikey ritmi paylaşır: alanlar arası boşluk tek
     * değerde olmalı, yoksa aynı pencerede alanlar farklı sıklıkta dizilir.
     */
    test('form alanları aynı dikey ritmi paylaşır', async ({ page }) => {
        await girisYap(page);
        await page.goto('/switchler');

        const ilk = await page.locator('a.switch-row').first().getAttribute('href');
        await page.goto(`${ilk}/portlar`);

        const izgaralar = await page.locator('.form-grid').evaluateAll(
            (kutular) => kutular.map((k) => getComputedStyle(k).rowGap),
        );

        const benzersiz = [...new Set(izgaralar.map((g) => Math.round(parseFloat(g) || 0)))];

        expect(benzersiz.length, `form ızgarası satır boşluğu çeşidi: ${benzersiz.join(', ')}`)
            .toBeLessThanOrEqual(1);
    });

    /**
     * Üst çubuk her ekranda aynı yükseklikte durur; içine eklenen bir düğme
     * (ör. ayrıntı düzeyi) onu büyütmemeli.
     */
    test('üst çubuk her sayfada aynı yükseklikte', async ({ page }) => {
        await girisYap(page);

        const yukseklikler = new Set();

        for (const adres of await sayfalar(page)) {
            await page.goto(adres);

            yukseklikler.add(await page.locator('.topbar').evaluate(
                (c) => Math.round(c.getBoundingClientRect().height),
            ));
        }

        expect(yukseklikler.size, `üst çubuk yüksekliği değişiyor: ${[...yukseklikler].join(', ')}`)
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
        await girisYap(page);

        const kayanlar = [];

        for (const adres of await sayfalar(page)) {
            await page.goto(adres);

            const tasma = await page.evaluate(
                () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
            );

            // Yuvarlama payı: 1 piksel taşma göze görünmez, 2 piksel kayar.
            if (tasma > 1) {
                kayanlar.push(`${adres} (+${tasma}px)`);
            }
        }

        expect(kayanlar, `yatay kayan sayfalar: ${kayanlar.join(', ')}`).toEqual([]);
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
        await girisYap(page);
        await page.goto('/sunucular');

        const katmanlar = await page.evaluate(() => {
            const sayi = (el) => {
                const z = el ? getComputedStyle(el).zIndex : 'auto';

                return z === 'auto' ? null : Number(z);
            };

            const yapiskan = [...document.querySelectorAll('*')].find(
                (el) => getComputedStyle(el).position === 'sticky' && sayi(el) !== null,
            );

            return {
                topbar: sayi(document.querySelector('.topbar')),
                yapiskan: sayi(yapiskan),
                yapiskanAd: yapiskan?.className ?? '(yok)',
            };
        });

        expect(katmanlar.topbar, 'üst çubuk yığılma bağlamı kurmuyor (z-index: auto)')
            .not.toBeNull();

        if (katmanlar.yapiskan !== null) {
            expect(
                katmanlar.topbar,
                `üst çubuk (${katmanlar.topbar}) yapışkan içeriğin (${katmanlar.yapiskanAd}: ${katmanlar.yapiskan}) altında`,
            ).toBeGreaterThan(katmanlar.yapiskan);
        }
    });
});
