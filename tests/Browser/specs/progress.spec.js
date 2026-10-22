import { expect, test } from '@playwright/test';
import { performLogin } from '../support/login.js';

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

    await performLogin(page);
    await page.goto('/settings/passwords');

    const bar = page.locator('[role="progressbar"]');
    await expect(bar.first()).toBeVisible({ timeout: 15000 });

    /*
     * ÖZET ŞERİT KAYDIRMADAN GÖRÜNMELİ.
     *
     * Telefonda (412×915) cihaz listesi sayfanın altında
     * ve biçim paneli ekranı dolduruyor; satır içindeki çubukların hiçbiri
     * görüntü alanına girmiyordu. Görünmeyen bir ilerleme göstergesinin
     * varlık sebebi yok.
     */
    const summary = page.locator('[data-progress-summary]');
    await expect(summary).toBeVisible();
    await expect(summary).toBeInViewport();

    /* Satır içindeki çubuk da okunabilir genişlikte olmalı. */
    const rowBar = page.locator('[data-progress-device] [role="progressbar"]').first();
    const width = (await rowBar.boundingBox())?.width ?? 0;
    expect(width).toBeGreaterThan(120);

    /* Çubuğun bulunduğu satırda "Değiştir" görünmemeli. */
    const row = page.locator('[data-progress-device]').filter({ has: bar });
    await expect(row.getByRole('button', { name: 'Değiştir' })).toHaveCount(0);

    await page.screenshot({ path: testInfo.outputPath('nys-ilerleme.png'), fullPage: false });

    /* Yenilemede silinecek iz. */
    await page.evaluate(() => {
        window.__nsmMarker = 'duruyor';
    });

    /*
     * Tur dışarıdan bitirilince çubuk kaybolmalı — ve sayfa YERİNDE
     * tazelendiği için iz yerinde kalmalı.
     */
    await expect(page.locator('[data-progress-device] [role="progressbar"]')).toHaveCount(0, { timeout: 90000 });
    await expect(summary).toHaveCount(0);

    await page.screenshot({ path: testInfo.outputPath('nys-ilerleme-bitis.png'), fullPage: false });

    expect(await page.evaluate(() => window.__nsmMarker ?? null)).toBe('duruyor');
});
