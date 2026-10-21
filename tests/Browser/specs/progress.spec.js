import { expect, test } from '@playwright/test';
import { girisYap } from '../support/login.js';

/**
 * Süren parola turunun ilerleme çubuğu — cihaz satırında.
 *
 * Tur kuyrukta çalışıyor ve bir switchte dört oturum ile üç commit sürüyor;
 * toplu turda onlarca cihaz aynı anda sırada bekliyor. Ekran o süre boyunca
 * yalnızca "kuyruğa alındı" derse kullanıcı hangi cihazın nerede olduğunu
 * göremez.
 *
 * ÖLÇÜLEN İKİ ŞEY:
 *
 *  1. Çubuk çiziliyor ve o satırdaki düğmeler gizleniyor mu?
 *  2. Turlar bitince sayfa TARAYICI YENİLEMESİ yapmadan tazeleniyor mu?
 *     Pencereye bir iz bırakılır; tam yenilemede o iz kaybolur.
 *
 * Tur kaydı testin DIŞINDA hazırlanır: gerçek bir switchin parolasını
 * değiştirmek görsel doğrulama için ödenecek bir bedel değil.
 */
test('cihaz satırında çubuk çizilir ve düğme gizlenir', async ({ page }, testInfo) => {
    test.setTimeout(120000);

    await girisYap(page);
    await page.goto('/ayarlar/parolalar');

    const cubuk = page.locator('[role="progressbar"]');
    await expect(cubuk.first()).toBeVisible({ timeout: 15000 });

    /*
     * ÖZET ŞERİT KAYDIRMADAN GÖRÜNMELİ.
     *
     * Telefonda (412×915) cihaz listesi sayfanın altında
     * ve biçim paneli ekranı dolduruyor; satır içindeki çubukların hiçbiri
     * görüntü alanına girmiyordu. Görünmeyen bir ilerleme göstergesinin
     * varlık sebebi yok.
     */
    const ozet = page.locator('[data-ilerleme-ozet]');
    await expect(ozet).toBeVisible();
    await expect(ozet).toBeInViewport();

    /* Satır içindeki çubuk da okunabilir genişlikte olmalı. */
    const satirCubugu = page.locator('[data-ilerleme-cihaz] [role="progressbar"]').first();
    const genislik = (await satirCubugu.boundingBox())?.width ?? 0;
    expect(genislik).toBeGreaterThan(120);

    /* Çubuğun bulunduğu satırda "Değiştir" görünmemeli. */
    const satir = page.locator('[data-ilerleme-cihaz]').filter({ has: cubuk });
    await expect(satir.getByRole('button', { name: 'Değiştir' })).toHaveCount(0);

    await page.screenshot({ path: testInfo.outputPath('nys-ilerleme.png'), fullPage: false });

    /* Yenilemede silinecek iz. */
    await page.evaluate(() => {
        window.__nsmIsaret = 'duruyor';
    });

    /*
     * Tur dışarıdan bitirilince çubuk kaybolmalı — ve sayfa YERİNDE
     * tazelendiği için iz yerinde kalmalı.
     */
    await expect(page.locator('[data-ilerleme-cihaz] [role="progressbar"]')).toHaveCount(0, { timeout: 90000 });
    await expect(ozet).toHaveCount(0);

    await page.screenshot({ path: testInfo.outputPath('nys-ilerleme-bitis.png'), fullPage: false });

    expect(await page.evaluate(() => window.__nsmIsaret ?? null)).toBe('duruyor');
});
