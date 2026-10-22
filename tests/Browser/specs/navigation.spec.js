import { expect, test } from '@playwright/test';

import { performLogin } from '../support/login.js';

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
async function loginAndMark(page) {
    await performLogin(page);
    await page.evaluate(() => { window.__navigationMarker = 'duruyor'; });
}

/** Üst menüden git ve adresin değiştiğini bekle. */
async function goFromMenu(page, path) {
    const connection = page.locator(`a[href$="${path}"]`).first();

    test.skip(await connection.count() === 0, `Menüde ${path} bağlantısı yok.`);

    await connection.click();
    await page.waitForFunction((target) => window.location.pathname === target, path, { timeout: 15000 });
}

test('menüden gezinmek sayfayı yenilemez', async ({ page }) => {
    await loginAndMark(page);

    for (const path of ['/switches', '/addressing', '/servers', '/users']) {
        await goFromMenu(page, path);

        // İşaret duruyorsa JavaScript bağlamı korunmuş, yani yenileme olmamış.
        await expect(await page.evaluate(() => window.__navigationMarker)).toBe('duruyor');
    }

    // Başlık da güncellenmeli; yoksa sekme eski sayfanın adını taşır.
    await expect(page).toHaveTitle(/Kullanıcılar/);
});

test('geri ve ileri de yenilemeden çalışır', async ({ page }) => {
    await loginAndMark(page);

    await goFromMenu(page, '/switches');
    await goFromMenu(page, '/servers');

    await page.goBack();
    await page.waitForFunction(() => window.location.pathname === '/switches', null, { timeout: 15000 });
    await expect(await page.evaluate(() => window.__navigationMarker)).toBe('duruyor');

    await page.goForward();
    await page.waitForFunction(() => window.location.pathname === '/servers', null, { timeout: 15000 });
    await expect(await page.evaluate(() => window.__navigationMarker)).toBe('duruyor');
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
    await loginAndMark(page);

    await goFromMenu(page, '/switches');
    await goFromMenu(page, '/servers');
    await goFromMenu(page, '/users');

    const once = await page.evaluate(() => document.documentElement.dataset.theme);

    await page.locator('[data-theme-toggle]').click();

    await expect
        .poll(() => page.evaluate(() => document.documentElement.dataset.theme))
        .not.toBe(once);
});

test('geçişten sonra pencere açılır', async ({ page }) => {
    await loginAndMark(page);

    await goFromMenu(page, '/switches');
    await goFromMenu(page, '/servers');

    await page.getByRole('button', { name: 'Yeni sunucu' }).click();
    await expect(page.locator('#server-add')).toBeVisible();
});

/**
 * Bildirim paneli üst çubuktadır, yani HER geçişte yeniden çizilir.
 * Düğüme doğrudan bağlanan bir dinleyici ilk geçişte kopardı.
 */
test('geçişten sonra bildirim paneli çalışır', async ({ page }) => {
    await loginAndMark(page);

    await goFromMenu(page, '/switches');
    await goFromMenu(page, '/servers');

    await page.locator('[data-notification-open]').click();
    await expect(page.locator('[data-notification-panel]')).toBeVisible();

    const request = page.waitForResponse(
        (response) => response.url().includes('/notifications/seen') && response.request().method() === 'POST',
    );

    await page.locator('[data-notification-read]').click();

    expect((await request).status()).toBe(200);
});
