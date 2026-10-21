import { expect, test } from '@playwright/test';

import { girisYap } from '../support/giris.js';

/**
 * Sayfa geçişleri tarayıcıyı YENİLEMEZ.
 *
 * Sunucu yine tam sayfa üretir; değişen tek şey gövdenin yerinde
 * değiştirilmesi (bkz. resources/js/components/navigation.js). Ekran beyaza
 * düşmez, tema ve betikler yeniden yüklenmez.
 *
 * ÖLÇÜM YÖNTEMİ: pencereye bir işaret bırakılır. Sayfa gerçekten yenilenirse
 * JavaScript bağlamı sıfırlanır ve işaret KAYBOLUR. "Hızlı göründü" gibi
 * öznel bir şey değil, yenilenip yenilenmediği ölçülür.
 */

/**
 * Giriş yapıp pencereye işareti bırakır.
 *
 * İşaret bu spec'e özgüdür (yenilenme ölçümü); ortak giriş adımına
 * karışmaz.
 */
async function girisYapVeIsaretle(page) {
    await girisYap(page);
    await page.evaluate(() => { window.__gezinmeIsareti = 'duruyor'; });
}

/** Üst menüden git ve adresin değiştiğini bekle. */
async function menudenGit(page, yol) {
    const baglanti = page.locator(`a[href$="${yol}"]`).first();

    test.skip(await baglanti.count() === 0, `Menüde ${yol} bağlantısı yok.`);

    await baglanti.click();
    await page.waitForFunction((hedef) => window.location.pathname === hedef, yol, { timeout: 15000 });
}

test('menüden gezinmek sayfayı yenilemez', async ({ page }) => {
    await girisYapVeIsaretle(page);

    for (const yol of ['/switchler', '/adresleme', '/sunucular', '/kullanicilar']) {
        await menudenGit(page, yol);

        // İşaret duruyorsa JavaScript bağlamı korunmuş, yani yenileme olmamış.
        await expect(await page.evaluate(() => window.__gezinmeIsareti)).toBe('duruyor');
    }

    // Başlık da güncellenmeli; yoksa sekme eski sayfanın adını taşır.
    await expect(page).toHaveTitle(/Kullanıcılar/);
});

test('geri ve ileri de yenilemeden çalışır', async ({ page }) => {
    await girisYapVeIsaretle(page);

    await menudenGit(page, '/switchler');
    await menudenGit(page, '/sunucular');

    await page.goBack();
    await page.waitForFunction(() => window.location.pathname === '/switchler', null, { timeout: 15000 });
    await expect(await page.evaluate(() => window.__gezinmeIsareti)).toBe('duruyor');

    await page.goForward();
    await page.waitForFunction(() => window.location.pathname === '/sunucular', null, { timeout: 15000 });
    await expect(await page.evaluate(() => window.__gezinmeIsareti)).toBe('duruyor');
});

/**
 * Geçişten sonra bileşenler HÂLÂ ÇALIŞMALI ve olaylar İKİLENMEMELİ.
 *
 * Gövde değiştiği için bileşenlerin kurulumu her geçişte yeniden koşuyor.
 * Delegasyon tekrara dayanıklı olmasaydı (lib/dom.js `on`) tek tık iki kez
 * işlenirdi — tema düğmesinde bu, koyudan açığa geçip hemen koyuya dönmek
 * demek olurdu, yani "hiçbir şey olmadı".
 */
test('geçişten sonra tema düğmesi bir kez çalışır', async ({ page }) => {
    await girisYapVeIsaretle(page);

    await menudenGit(page, '/switchler');
    await menudenGit(page, '/sunucular');
    await menudenGit(page, '/kullanicilar');

    const once = await page.evaluate(() => document.documentElement.dataset.theme);

    await page.locator('[data-theme-toggle]').click();

    await expect
        .poll(() => page.evaluate(() => document.documentElement.dataset.theme))
        .not.toBe(once);
});

test('geçişten sonra pencere açılır', async ({ page }) => {
    await girisYapVeIsaretle(page);

    await menudenGit(page, '/switchler');
    await menudenGit(page, '/sunucular');

    await page.getByRole('button', { name: 'Yeni sunucu' }).click();
    await expect(page.locator('#sunucu-ekle')).toBeVisible();
});

/**
 * Bildirim paneli üst çubuktadır, yani HER geçişte yeniden çizilir.
 * Düğüme doğrudan bağlanan bir dinleyici ilk geçişte kopardı.
 */
test('geçişten sonra bildirim paneli çalışır', async ({ page }) => {
    await girisYapVeIsaretle(page);

    await menudenGit(page, '/switchler');
    await menudenGit(page, '/sunucular');

    await page.locator('[data-bildirim-ac]').click();
    await expect(page.locator('[data-bildirim-panel]')).toBeVisible();

    const istek = page.waitForResponse(
        (yanit) => yanit.url().includes('/bildirimler/okundu') && yanit.request().method() === 'POST',
    );

    await page.locator('[data-bildirim-okundu]').click();

    expect((await istek).status()).toBe(200);
});
