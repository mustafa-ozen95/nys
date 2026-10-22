/**
 * Görsel testlerin ortak giriş adımı.
 *
 * Her spec dosyası aynı yardımcının kendi kopyasını taşırsa giriş ekranındaki
 * tek bir değişiklik hepsini birden bozar: giriş ekranı Kimlik Merkezi'ni öne
 * alıp parola formunu KATLANIR bir bloğa taşıdığında alanlar DOM'da durur ama
 * görünmez, doldurmak 30 saniyede zaman aşımına düşer ve bütün takım — tasarım
 * nöbetçileri dahil ("hiçbir sayfa yatay kaymaz", "kartlar aynı genişlikte") —
 * koşmaz.
 *
 * Adım tek yerde durur: ekran bir daha değişirse bir dosya güncellenir.
 */

export const USER = {
    email: process.env.E2E_ADMIN_EMAIL,
    password: process.env.E2E_ADMIN_PASSWORD,
};

export async function performLogin(page) {
    await page.goto('/login');

    /* Merkez açıkken parola formu katlı gelir; alanlar ancak açılınca görünür. */
    const collapsible = page.locator('details.gate__collapsible');

    if (await collapsible.count()) {
        await collapsible.evaluate((el) => { el.open = true; });
    }

    await page.fill('#email', USER.email);
    await page.fill('#password', USER.password);
    await page.click('button[type="submit"]');

    /* Giriş başarısızsa hata metnini teste taşı; sessizce zaman aşımına düşme. */
    const error = page.locator('.alert--danger');

    await Promise.race([
        page.locator('.topbar').waitFor({ state: 'visible', timeout: 15000 }),
        error.waitFor({ state: 'visible', timeout: 15000 }),
    ]);

    if (await error.isVisible()) {
        throw new Error(`Giriş yapılamadı: ${(await error.innerText()).trim()}`);
    }
}
