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

export const KULLANICI = {
    email: process.env.E2E_ADMIN_EMAIL,
    password: process.env.E2E_ADMIN_PASSWORD,
};

export async function girisYap(page) {
    await page.goto('/giris');

    /* Merkez açıkken parola formu katlı gelir; alanlar ancak açılınca görünür. */
    const katlanir = page.locator('details.gate__katlanir');

    if (await katlanir.count()) {
        await katlanir.evaluate((el) => { el.open = true; });
    }

    await page.fill('#email', KULLANICI.email);
    await page.fill('#password', KULLANICI.password);
    await page.click('button[type="submit"]');

    /* Giriş başarısızsa hata metnini teste taşı; sessizce zaman aşımına düşme. */
    const hata = page.locator('.alert--danger');

    await Promise.race([
        page.locator('.topbar').waitFor({ state: 'visible', timeout: 15000 }),
        hata.waitFor({ state: 'visible', timeout: 15000 }),
    ]);

    if (await hata.isVisible()) {
        throw new Error(`Giriş yapılamadı: ${(await hata.innerText()).trim()}`);
    }
}
