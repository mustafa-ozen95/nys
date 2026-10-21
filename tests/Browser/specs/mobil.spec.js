import { expect, test } from '@playwright/test';

import { girisYap } from '../support/giris.js';

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
        await girisYap(page);
        await page.goto('/switchler');

        const ilk = await page.locator('a.switch-row').first().getAttribute('href');

        const adresler = [
            '/switchler', ilk, `${ilk}/portlar`, `${ilk}/toplama`, `${ilk}/firewall`,
            `${ilk}/politikalar`, `${ilk}/bgp`, '/bgp', '/adresleme', '/sunucular',

            // Yönetim ekranları da listede: kayıtlar sayfası sayfalama şeridi
            // yüzünden taşıyordu ve test onu hiç gezmiyordu.
            '/kullanicilar', '/kayitlar', '/ayarlar', '/ayarlar/parolalar', '/config', '/prefix-anonsu',
        ];

        for (const adres of adresler) {
            await page.goto(adres);
            await page.waitForLoadState('networkidle');

            const tasma = await page.evaluate(
                () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
            );

            expect(tasma, `${adres} yatay kayıyor`).toBeLessThanOrEqual(1);
        }
    });

    /**
     * Bir parmak hareketi bir bölme ilerletmeli.
     *
     * Bölmeler ekrandan darsa tek geçiş birkaç hareket sürer ve kaydırma
     * bölmenin ortasında kalır.
     */
    test('her bölme tam ekran genişliğinde', async ({ page }) => {
        await girisYap(page);
        await page.goto('/switchler');

        const ilk = await page.locator('a.switch-row').first().getAttribute('href');
        await page.goto(`${ilk}/portlar`);
        await page.waitForLoadState('networkidle');

        const olcum = await page.evaluate(() => ({
            /*
             * Yalnızca GÖRÜNEN bölmeler ölçülür: bazı ekranlarda detay paneli
             * hiç çizilmez (display: none) ve genişliği sıfırdır.
             */
            genislikler: [...document.querySelectorAll('.columns > .column, .columns > .detail')]
                .filter((el) => getComputedStyle(el).display !== 'none')
                .map((el) => Math.round(el.getBoundingClientRect().width)),
            ekran: document.documentElement.clientWidth,
        }));

        expect(olcum.genislikler.length).toBeGreaterThan(1);

        for (const genislik of olcum.genislikler) {
            expect(genislik).toBeGreaterThanOrEqual(olcum.ekran - 2);
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
        await girisYap(page);
        await page.goto('/switchler');

        const sw = await page.locator('a.switch-row').first().getAttribute('href');

        const olc = () => page.evaluate(() => {
            const serit = document.querySelector('.columns');
            const bolmeler = [...serit.querySelectorAll(':scope > .column, :scope > .detail')]
                .filter((b) => getComputedStyle(b).display !== 'none');
            const son = bolmeler[bolmeler.length - 1];

            return Math.abs(serit.scrollLeft - (son.offsetLeft - serit.offsetLeft));
        });

        // Seçim yapılan ekranlar: seçimden sonra DETAY açılmalı.
        for (const [adres, secici] of [
            ['/bgp', 'a.bgp-row'],
            ['/sunucular', 'a.grid-row'],
            // Portlarda seçim sayfa yenilemeden yapılır; kural yine geçerli.
            [`${sw}/portlar`, '.grid-row.port-row'],
        ]) {
            await page.goto(adres);

            const satir = page.locator(secici).first();

            if (await satir.count() === 0) {
                continue;
            }

            await satir.click();
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(400);

            expect(await olc(), `${adres} seçimden sonra detayı açmıyor`).toBeLessThanOrEqual(2);
        }

        // Seçim olmayan ekranlar: içerik/liste açılmalı.
        for (const adres of [`${sw}/firewall`, sw, '/switchler']) {
            await page.goto(adres);
            await page.waitForLoadState('networkidle');

            expect(await olc(), `${adres} en sağdaki bölmeyi açmıyor`).toBeLessThanOrEqual(2);
        }
    });

    /**
     * Üst menüde "Prefix anonsu" iki satıra düşüyor, "Kullanıcılar" ortadan
     * kesiliyordu; şerit kayar, yazı bölünmez.
     */
    test('üst menü bağlantıları tek satırda kalır', async ({ page }) => {
        await girisYap(page);
        await page.goto('/switchler');

        const yukseklikler = await page.locator('.topbar__link').evaluateAll(
            (baglantilar) => baglantilar.map((b) => Math.round(b.getBoundingClientRect().height)),
        );

        // Hepsi aynı yükseklikte olmalı: biri iki satıra düşerse ayrışır.
        expect(new Set(yukseklikler).size).toBe(1);
    });

    /**
     * Panel içindeki satırlar telefonda yığılır; ad ile açıklama yan yana
     * sıkışıp okunmaz hâle gelmemeli.
     */
    test('panel satırları yığılır', async ({ page }) => {
        await girisYap(page);
        await page.goto('/switchler');

        const ilk = await page.locator('a.switch-row').first().getAttribute('href');
        await page.goto(`${ilk}/firewall`);

        const satir = page.locator('.panel__body .row--between').first();
        test.skip(await satir.count() === 0, 'ekranda panel satırı yok');

        const kutular = await satir.locator('> *').evaluateAll(
            (cocuklar) => cocuklar.map((c) => Math.round(c.getBoundingClientRect().top)),
        );

        // En az iki farklı satır olmalı: hepsi aynı hizadaysa yığılmamış demektir.
        expect(new Set(kutular).size).toBeGreaterThan(1);
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
        await girisYap(page);
        await page.goto('/kullanicilar');
        await page.locator('a[href*="/yetkiler"]').first().click();
        await page.waitForURL(/yetkiler/);

        const hucreler = page.locator('.table tbody td');
        test.skip(await hucreler.count() === 0, 'ekranda tablo yok');

        const etiketler = await hucreler.evaluateAll((liste) => liste.map(
            (hucre) => getComputedStyle(hucre, '::before').content,
        ));

        // Hiçbir hücre boş etiketle kalmamalı.
        expect(etiketler.filter((e) => !e || e === 'none' || e === '""')).toHaveLength(0);
    });

    /**
     * Alana odaklanınca sayfa yakınlaşmamalı.
     *
     * iOS Safari, yazı boyutu 16 pikselin altında olan alanda sayfayı
     * kendiliğinden büyütür ve geri almaz. Eşiği geçmek tek doğru çözümdür;
     * viewport'a "user-scalable=no" yazmak parmakla büyütmeyi de öldürür.
     */
    test('form alanları yakınlaştırma eşiğinin üstünde', async ({ page }) => {
        await girisYap(page);

        // Farklı alan türlerinin geçtiği ekranlar: ayar formu, config metni,
        // yetki matrisindeki dar seçim kutuları, kayıt filtreleri.
        const adresler = ['/ayarlar/cihaz', '/config', '/kayitlar'];

        await page.goto('/kullanicilar');
        const yetki = await page.locator('a[href*="/yetkiler"]').first().getAttribute('href');

        if (yetki) {
            adresler.push(yetki);
        }

        for (const adres of adresler) {
            await page.goto(adres);
            await denetle(page, adres);
        }
    });

    async function denetle(page, adres) {
        const boyutlar = await page.locator('input, select, textarea').evaluateAll(
            (alanlar) => alanlar
                .filter((alan) => alan.type !== 'hidden' && alan.type !== 'checkbox')
                .map((alan) => ({
                    ad: alan.name || alan.id || alan.tagName,
                    boyut: parseFloat(getComputedStyle(alan).fontSize),
                })),
        );

        const kucukler = boyutlar.filter((alan) => alan.boyut < 16);

        expect(kucukler, `${adres} → 16px altında alan var: ${JSON.stringify(kucukler)}`).toHaveLength(0);
    }
});
