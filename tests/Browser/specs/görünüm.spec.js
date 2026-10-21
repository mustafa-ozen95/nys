import { expect, test } from '@playwright/test';

import { girisYap } from '../support/giris.js';

/*
 * Panel HER EKRANDA aynı adı taşır.
 *
 * Ad tek kaynaktan gelir — hangisi olduğu .env'de verilir. Test bu yüzden
 * sabit bir ad beklemez; giriş ekranındaki adı üst çubukta arar.
 */
test('giriş ekranı', async ({ page }) => {
    await page.goto('/giris');
    const girisAdi = (await page.locator('.gate__mark').innerText()).trim();

    await girisYap(page);

    /* Aynı ad üst çubukta da durmalı: iki ekran, tek kaynak. */
    await expect(page.locator('.topbar__mark')).toHaveText(girisAdi);

    await page.goto('/giris');
    await page.screenshot({ path: `tests/Browser/shots/giris-${test.info().project.name}.png`, fullPage: true });
});

test('genel bakış', async ({ page }) => {
    await girisYap(page);
    await expect(page.locator('.column__head .eyebrow')).toHaveText('Switchler');
    await page.screenshot({ path: `tests/Browser/shots/genel-bakis-${test.info().project.name}.png`, fullPage: true });
});

test('genel bakış — açık tema', async ({ page }) => {
    await girisYap(page);
    await page.click('[data-theme-toggle]');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await page.screenshot({ path: `tests/Browser/shots/genel-bakis-acik-${test.info().project.name}.png`, fullPage: true });
});

test('switch listesi', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    await expect(page.locator('.column__head .eyebrow')).toHaveText('Switchler');
    await page.screenshot({ path: `tests/Browser/shots/switchler-${test.info().project.name}.png`, fullPage: true });
});

test('switch detayı', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await expect(page.locator('.panel__title').first()).toBeVisible();
    await page.screenshot({ path: `tests/Browser/shots/switch-detay-${test.info().project.name}.png`, fullPage: true });
});

test('yetki matrisi', async ({ page }) => {
    await girisYap(page);
    await page.goto('/kullanicilar');
    await page.locator('a[href*="/yetkiler"]').first().click();
    await expect(page.locator('.tabs')).toBeVisible();
    await page.screenshot({ path: `tests/Browser/shots/yetki-matrisi-${test.info().project.name}.png`, fullPage: true });
});

test('port listesi ve trafik grafiği', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);

    // Üst menüde de "Portlar" var; buradaki nesne sütunundaki bağlantıdır.
    await page.locator('.column .row-item', { hasText: 'Portlar' }).click();
    await page.waitForURL(/\/portlar$/);
    // .port-row başlık satırına da uyar ve o mobilde gizlidir; gerçek satır aranır.
    await expect(page.locator('button.port-row').first()).toBeVisible();

    await page.screenshot({ path: `tests/Browser/shots/portlar-${test.info().project.name}.png`, fullPage: true });

    // Bir porta tıklayınca trafik paneli açılmalı.
    await page.locator('button.port-row').first().click();
    await expect(page.locator('[data-trafik-paneli]')).toBeVisible();
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `tests/Browser/shots/trafik-${test.info().project.name}.png`, fullPage: true });
});

test('sunucu listesi ve formu', async ({ page }) => {
    await girisYap(page);
    await page.goto('/sunucular');
    await expect(page.locator('.column__head .eyebrow')).toHaveText('Sunucular');
    await page.screenshot({ path: `tests/Browser/shots/sunucular-${test.info().project.name}.png`, fullPage: true });

    // Sunucu ekleme ayrı sayfa değil, pencere: liste görünürken doldurulur.
    await page.getByRole('button', { name: 'Yeni sunucu' }).click();
    const pencere = page.locator('#sunucu-ekle');
    await expect(pencere).toBeVisible();
    await expect(pencere.locator('#short_name')).toBeVisible();
    await page.screenshot({ path: `tests/Browser/shots/sunucu-formu-${test.info().project.name}.png`, fullPage: true });
});

test('etkinlik kayıtları', async ({ page }) => {
    await girisYap(page);
    await page.goto('/kayitlar');
    await expect(page.locator('.column__head .eyebrow')).toHaveText('Etkinlik kayıtları');
    await page.screenshot({ path: `tests/Browser/shots/kayitlar-${test.info().project.name}.png`, fullPage: true });
});

test('prefix anonsu — önizleme', async ({ page }) => {
    await girisYap(page);
    await page.goto('/prefix-anonsu');
    await page.fill('#prefixes', '192.0.2.0/24\n198.18.0.0/22');

    // Alıcılar veritabanından yönetilir; canlıda tanımlı olmayabilir.
    const alicilar = page.locator('.recipient-list input[type="checkbox"]');

    if (await alicilar.count()) {
        await alicilar.first().check();
        await page.getByRole('button', { name: 'Önizle' }).click();
        await expect(page.locator('.announcement-preview').first()).toBeVisible();
    }

    await page.screenshot({ path: `tests/Browser/shots/anons-${test.info().project.name}.png`, fullPage: true });
});

/**
 * Anons yetkisi ekranı: hangi kaynak ne dedi, ve hiçbiri onaylamayan blok
 * gönderimi kapatıyor mu.
 *
 * Üç blok ve AS64496 için beklenen durumları:
 *
 *   192.0.2.0/24   ROA ✓  IRR ✓   temiz geçer
 *   198.51.100.0/24   ROA ✓  IRR ✗   geçer ama eksiği yazılır (IRR nesnesi AS64497'in)
 *   203.0.113.0/24   ROA ✗  IRR ✗   hiçbir kanıt yok, talep durur (ikisi de AS64510)
 *
 * Ortadaki satır iki kaynağın BAĞIMSIZ olduğunun kanıtıdır; RPKI'dan
 * türetilmiş sözde-nesne elenmeseydi orada da yeşil görünürdü.
 *
 * ASN listesi cihazlardan gelir ve müşteri ASN'sini içermeyebilir; anons
 * müşteri numarası için de yapılabildiğinden AS64496 ELLE girilir.
 */
test('anons yetkisi kaynakları ayrı gösterir ve kanıtsız bloğu durdurur', async ({ page }) => {
    await girisYap(page);
    await page.goto('/prefix-anonsu');

    const alicilar = page.locator('.recipient-list input[type="checkbox"]');

    // Canlıda alıcı tanımlı olmayabilir; veri üretmeyiz, atlarız.
    test.skip(await alicilar.count() === 0, 'Tanımlı alıcı yok.');

    await page.locator('select#asn').selectOption('__elle__');
    await page.locator('#asn-elle').fill('AS64496');

    await page.fill('#prefixes', '192.0.2.0/24\n198.51.100.0/24\n203.0.113.0/24');
    await alicilar.first().check();
    await page.getByRole('button', { name: 'Önizle' }).click();

    const panel = page.locator('.anons-yetki');
    await expect(panel).toBeVisible();

    await expect(panel.getByText('RPKI ROA (RIPE)').first()).toBeVisible();
    await expect(panel.getByText('IRR route nesnesi (RADB)').first()).toBeVisible();

    // Temiz blok: iki kaynak da onaylar, eksik satırı hiç çizilmez.
    const temiz = panel.locator('.anons-yetki-row', { hasText: '192.0.2.0/24' });
    await expect(temiz.locator('.badge--green')).toHaveCount(2);
    await expect(temiz.locator('.anons-yetki-row__gerekce')).toHaveCount(0);

    // Eksik blok: gönderimi durdurmaz ama eksiği YAZILIR ve engel gibi kızarmaz.
    const eksik = panel.locator('.anons-yetki-row', { hasText: '198.51.100.0/24' });
    await expect(eksik.locator('.badge--green')).toHaveCount(1);
    await expect(eksik.locator('.badge--red')).toHaveCount(1);
    await expect(eksik.locator('.anons-yetki-row__gerekce')).toBeVisible();
    await expect(eksik.locator('.anons-yetki-row__gerekce--engel')).toHaveCount(0);

    // Kanıtsız blok: iki kaynak da reddeder ve gerekçe engel olarak işaretlenir.
    const durdu = panel.locator('.anons-yetki-row', { hasText: '203.0.113.0/24' });
    await expect(durdu.locator('.badge--red')).toHaveCount(2);
    await expect(durdu.locator('.anons-yetki-row__gerekce--engel')).toBeVisible();

    // Engellenen talepte gönder düğmesi YOKTUR; devre dışı değil, hiç yok.
    await expect(page.getByRole('button', { name: 'Gönder', exact: true })).toHaveCount(0);
    await expect(page.locator('.alert--danger')).toBeVisible();

    await page.screenshot({ path: `tests/Browser/shots/anons-yetki-${test.info().project.name}.png`, fullPage: true });
});

/**
 * Anons ekranında AS numarası SORULUR, listeye kilitlenmez.
 *
 * Anons yalnızca kurumun kendi ASN'iyle yapılmaz, müşteri numarasıyla da
 * istenebilir. Müşterinin numarası hiçbir switchte yazmaz; listede olmayan bir
 * numara elle girilebilmeli.
 *
 * Liste ile elle giriş kutusu aynı adı taşır ve her an yalnızca birinde "name"
 * bulunur; ikisi birden gönderilirse sunucuya iki ASN gider.
 */
test('anons ekranında AS numarası elle girilebilir', async ({ page }) => {
    await girisYap(page);
    await page.goto('/prefix-anonsu');

    const liste = page.locator('select#asn');
    const kutu = page.locator('#asn-elle');

    await expect(kutu).toBeHidden();
    await expect(liste).toHaveAttribute('name', 'asn');

    await liste.selectOption('__elle__');
    await expect(kutu).toBeVisible();
    await expect(kutu).toHaveAttribute('name', 'asn');
    await expect(liste).not.toHaveAttribute('name', /.+/);

    await liste.selectOption('');
    await expect(kutu).toBeHidden();
    await expect(liste).toHaveAttribute('name', 'asn');
});

/**
 * Önizleme durumu da yatay kaymaz.
 *
 * Çerçevenin geneli tasarim.spec.js'te ölçülüyor ama oradaki tur yalnızca GET
 * sayfalarını geziyor; yetki paneli ancak formu göndererek çizilir ve gerekçe
 * satırı uzun metin taşır.
 */
test('anons yetkisi paneli sayfayı yatay kaydırmaz', async ({ page }) => {
    await girisYap(page);
    await page.goto('/prefix-anonsu');

    const alicilar = page.locator('.recipient-list input[type="checkbox"]');
    test.skip(await alicilar.count() === 0, 'Tanımlı alıcı yok.');

    await page.fill('#prefixes', '198.51.100.0/24');
    await alicilar.first().check();
    await page.getByRole('button', { name: 'Önizle' }).click();
    await expect(page.locator('.anons-yetki')).toBeVisible();

    const tasma = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );

    expect(tasma).toBeLessThanOrEqual(1);
});

/**
 * "Tümünü okundu işaretle" GERÇEKTEN işaretler.
 *
 * Üç kez aynı hata: gerekçe ara katmanı durum değiştiren bir ucu 422 ile
 * reddediyor, ekran hiçbir şey söylemiyor. Bu sefer uç bir fetch çağrısıydı;
 * ne PHP testleri (ara katman kapalı) ne de form tarayıcısı görüyordu.
 *
 * Ölçülen şey isteğin SONUCU: düğmeye basınca hata bildirimi ÇIKMAMALI ve
 * sayaç görünmez olmalı. Okunmamış bildirim yoksa bile ilk koşul anlamlıdır —
 * 422 dönen bir uç kırmızı bildirim üretir.
 */
test('bildirimleri okundu işaretlemek çalışır', async ({ page }) => {
    await girisYap(page);

    await page.locator('[data-bildirim-ac]').click();
    const panel = page.locator('[data-bildirim-panel]');
    await expect(panel).toBeVisible();

    /*
     * İSTEĞİN SONUCU beklenir, sonra iddia edilir.
     *
     * İlk yazımda tıklamadan hemen sonra "kırmızı bildirim yok" deniyordu ve
     * test muafiyet kaldırılmışken bile GEÇİYORDU: yokluk iddiası, henüz
     * tamamlanmamış isteği yarışta geçiyor. Ölçülen şey artık ucun kendisi.
     */
    const istek = page.waitForResponse(
        (yanit) => yanit.url().includes('/bildirimler/okundu') && yanit.request().method() === 'POST',
    );

    await panel.locator('[data-bildirim-okundu]').click();

    const yanit = await istek;

    // 422 = gerekçe ara katmanı ucu reddetti; düğme sessizce ölmüş demektir.
    expect(yanit.status()).toBe(200);

    await expect(page.locator('.toast--danger')).toHaveCount(0);
    await expect(page.locator('[data-bildirim-sayac]')).toBeHidden();
});

test('port seçilince liste görünür kalır', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'Portlar' }).click();
    await page.waitForURL(/\/portlar$/);

    const satirlar = page.locator('button.port-row');
    await expect(satirlar.first()).toBeVisible();

    await satirlar.first().click();

    // Grafik yüklenirken durum yazısı listeyi örtmemeli.
    await expect(satirlar.first()).toBeVisible();
    await expect(page.locator('[data-trafik-paneli]')).toBeVisible();

    await page.locator('.chart-pair').waitFor({ state: 'visible' });
    await expect(satirlar.first()).toBeVisible();
});

test('bir grafiğin üzerine gelince diğeri de aynı noktayı gösterir', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'masaüstü', 'imleçle etkileşim yalnızca masaüstünde anlamlı');

    await girisYap(page);
    await page.goto('/switchler');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'Portlar' }).click();
    await page.waitForURL(/\/portlar$/);

    // Trafiği olan bir port seçilir; boş seride vurgulanacak nokta olmaz.
    await page.locator('button.port-row').first().click();
    await page.locator('.chart-pair').waitFor({ state: 'visible' });
    await page.waitForTimeout(500);

    const veriVar = await page.evaluate(() => window.__trafikGrafikleri?.length === 2
        && window.__trafikGrafikleri[0].data.labels.length > 2);

    test.skip(!veriVar, 'bu portta gösterilecek trafik verisi yok');

    // İlk grafiğin ortasına gelinir.
    const kutu = await page.locator('.chart-pair__item').first().locator('canvas').boundingBox();
    await page.mouse.move(kutu.x + kutu.width / 2, kutu.y + kutu.height / 2);
    await page.waitForTimeout(200);

    const aktif = await page.evaluate(
        () => window.__trafikGrafikleri.map((g) => g.getActiveElements().length),
    );

    // İkisinde de bir nokta vurgulu olmalı.
    expect(aktif[0]).toBeGreaterThan(0);
    expect(aktif[1], 'ikinci grafik eşleşmedi').toBeGreaterThan(0);
});

test('genel bakışta switche tıklayınca genel bakış açılır', async ({ page }) => {
    await girisYap(page);
    await page.locator('a.switch-row').first().click();

    // Portlar sayfası değil, switchin genel bakışı açılmalı.
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await expect(page.locator('.column__head .eyebrow').filter({ hasText: 'Genel bakış' })).toBeVisible();
});

test('kaydırınca tablo başlığı içeriğin altında kalmaz', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'Portlar' }).click();
    await page.waitForURL(/\/portlar$/);

    const govde = page.locator('.column--fill .column__body');
    await govde.evaluate((el) => el.scrollTo(0, 400));
    await page.waitForTimeout(200);

    // Başlık yapışkandır; zemini saydam olursa satırlar altından görünür.
    const zemin = await page.locator('.port-row__head').evaluate(
        (el) => getComputedStyle(el).backgroundColor,
    );

    expect(zemin).not.toBe('rgba(0, 0, 0, 0)');
    expect(zemin).not.toBe('transparent');

    await page.screenshot({ path: `tests/Browser/shots/kaydirma-${test.info().project.name}.png`, fullPage: false });
});

test('seçili zaman aralığı görünür biçimde ayrışır', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'Portlar' }).click();
    await page.waitForURL(/\/portlar$/);
    await page.locator('button.port-row').first().click();

    const secili = page.locator('[data-trafik-araligi][aria-pressed="true"]');
    const bosta = page.locator('[data-trafik-araligi][aria-pressed="false"]').first();

    await expect(secili).toHaveCount(1);

    const zemin = (konum) => konum.evaluate((el) => getComputedStyle(el).backgroundColor);

    // Seçili düğmenin zemini diğerlerinden farklı olmalı.
    expect(await zemin(secili)).not.toBe(await zemin(bosta));

    // Başka bir aralığa geçince seçim onunla birlikte taşınmalı.
    await page.locator('[data-trafik-araligi="24h"]').click();
    await expect(page.locator('[data-trafik-araligi="24h"]')).toHaveAttribute('aria-pressed', 'true');
    await expect(secili).toHaveCount(1);

    await page.waitForTimeout(800);
    // Grafik çizilmeden görüntü alınırsa panel boş çıkıyor.
    await page.locator('.chart-pair').waitFor({ state: 'visible' });
    await page.waitForTimeout(400);
    await page.locator('[data-trafik-paneli]').screenshot({
        path: `tests/Browser/shots/aralik-${test.info().project.name}.png`,
    });
});

test('klavyeyle gezinme gerçekten çalışır', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'Portlar' }).click();
    await page.waitForURL(/\/portlar$/);

    // ↑↓ satırlar arasında dolaşır.
    await page.locator('button.port-row').first().focus();
    const ilk = await page.evaluate(() => document.activeElement.textContent.trim().slice(0, 12));
    await page.keyboard.press('ArrowDown');
    const ikinci = await page.evaluate(() => document.activeElement.textContent.trim().slice(0, 12));

    expect(ikinci).not.toBe(ilk);

    // → seçili satırı açar: trafik paneli görünür olmalı.
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('[data-trafik-paneli]')).toBeVisible();

    // "/" filtre alanına odaklanır.
    await page.keyboard.press('Escape');
    await page.locator('body').click({ position: { x: 5, y: 300 } });
    await page.keyboard.press('/');
    await expect(page.locator('.filter__input')).toBeFocused();
});

test('genel bakıştan porta tıklayınca detay açık gelir', async ({ page }) => {
    await girisYap(page);

    /*
     * "Yoğun portlar" ÖLÇÜME bağlıdır: eşiğin üstünde trafik yoksa liste hiç
     * çizilmez. Canlıda veri üretmeyiz, atlarız — atlamayan test trafiğin
     * düştüğü anda kendiliğinden kırılıyordu.
     */
    const yogunPort = page.locator('.busy-row').first();
    test.skip(await yogunPort.count() === 0, 'Şu an eşiğin üstünde trafik taşıyan port yok.');

    const portAdi = (await yogunPort.locator('.busy-row__port').textContent()).trim();
    await yogunPort.click();

    await page.waitForURL(/\/portlar\?port=/);

    // Panel kendiliğinden açılmış ve doğru portu göstermeli.
    await expect(page.locator('[data-trafik-paneli]')).toBeVisible();
    await expect(page.locator('[data-trafik-baslik]')).toHaveText(portAdi);
    await expect(page.locator(`button.port-row[aria-selected="true"]`)).toHaveCount(1);

    await page.waitForTimeout(800);
    await page.screenshot({ path: `tests/Browser/shots/porta-gecis-${test.info().project.name}.png`, fullPage: false });
});

test('sunucuya tıklayınca notu ve bilgileri açılır', async ({ page }) => {
    await girisYap(page);
    await page.goto('/sunucular');

    // Seçim yapılmadan detay paneli görünmez.
    await expect(page.locator('.detail')).toHaveCount(0);

    await page.locator('a.server-row').first().click();
    await page.waitForURL(/\?.*server=[0-9a-f-]{36}/);

    await expect(page.locator('.detail')).toBeVisible();
    await expect(page.locator('.eyebrow', { hasText: 'Not' })).toBeVisible();
    await expect(page.locator('a.server-row[aria-selected="true"]')).toHaveCount(1);

    await page.screenshot({ path: `tests/Browser/shots/sunucu-detay-${test.info().project.name}.png`, fullPage: false });
});

/**
 * Kendi kendine parola sıfırlama ve kayıt YOKTUR.
 *
 * Kural: parola sıfırlama ve üye kaydı kullanıcıya açık değildir; parolayı
 * yönetici değiştirir, üyeyi yönetici oluşturur. Giriş ekranında böyle bir
 * bağlantı çıkarsa kural sessizce delinmiş demektir.
 */
test('giriş ekranında kendi kendine sıfırlama yolu yok', async ({ page }) => {
    await page.goto('/giris');

    await expect(page.getByRole('link', { name: 'Parolamı unuttum' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Kayıt ol' })).toHaveCount(0);

    await page.screenshot({ path: `tests/Browser/shots/giris-sifirlama-yok-${test.info().project.name}.png`, fullPage: true });
});

test('adresleme ekranı blokları ve kullanım durumunu gösterir', async ({ page }) => {
    await girisYap(page);
    await page.goto('/adresleme');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: `tests/Browser/shots/adresleme-${test.info().project.name}.png`, fullPage: true });

    // Kesin eşleşme: form ipuçlarında da "blokları" geçiyor.
    await expect(page.getByText('Bloklar', { exact: true })).toBeVisible();
});

test('yeni blok penceresi açılır', async ({ page }, testInfo) => {
    await girisYap(page);
    await page.goto('/adresleme');
    await page.getByRole('button', { name: 'Yeni blok' }).click();

    const pencere = page.locator('#blok-ekle');
    await expect(pencere).toBeVisible();

    // Maske listeden seçilir, elle CIDR yazılmaz.
    await expect(page.locator('#prefix_length option', { hasText: '/24' })).toHaveCount(1);

    // Önce yöntem seçilir; erişim switchi ondan sonra sorulur.
    await page.selectOption('#blok-tur', 'port');
    await page.selectOption('#blok-erisim', { index: 1 });

    // Port listesi sunucudan dolar, elle yazılmaz. VLAN alanı port
    // yönteminde hiç görünmez.
    const portSecimi = page.locator('#blok-port');
    await expect(portSecimi).toBeVisible();
    await expect(portSecimi.locator('option')).not.toHaveCount(1);
    await expect(page.locator('#blok-vlan')).toBeHidden();

    /*
     * Pencere gövdesi kaydırma gerektirmemeli.
     *
     * Telefonda bu geçerli değil: ağ, maske, amaç, başlık, yöntem, switch ve
     * port aynı ekrana sığmaz ve gövde içinde kaydırmak doğru davranıştır.
     * Masaüstü ve tablette kaydırma hâlâ bir kusurdur.
     */
    if (testInfo.project.name !== 'mobil') {
        const kaydirma = await page.locator('#blok-ekle .modal__body').evaluate(
            (el) => el.scrollHeight - el.clientHeight,
        );
        expect(kaydirma, 'pencere kaydırma gerektiriyor').toBeLessThanOrEqual(1);
    }

    await page.screenshot({ path: `tests/Browser/shots/adresleme-blok-ekle-${test.info().project.name}.png` });

    await page.keyboard.press('Escape');
    await expect(pencere).toBeHidden();
});

test('blok kaydetmeden önce yazılı onay ister', async ({ page }) => {
    await girisYap(page);
    await page.goto('/adresleme');
    await page.getByRole('button', { name: 'Yeni blok' }).click();

    await page.fill('#network', '10.222.111.0');
    await page.selectOption('#prefix_length', '24');
    await page.selectOption('#blok-tur', 'port');
    await page.selectOption('#blok-erisim', { index: 1 });
    await page.selectOption('#blok-port', { index: 1 });

    await page.locator('#blok-ekle button[type="submit"]').click();

    // Kaydetmez; ne yapılacağını özetleyip bloğu tekrar yazmasını ister.
    const onay = page.locator('[data-confirm-dialog]');
    await expect(onay).toBeVisible();
    await expect(onay.locator('[data-confirm-message]')).toContainText('10.222.111.0/24');
    await expect(onay.locator('[data-confirm-message]')).toContainText('route edilecek');
    // Yol zincir olarak okunmalı; seçilen port liste yenilenirken kaybolmamalı.
    await expect(onay.locator('[data-confirm-message]')).toContainText('→');
    await expect(onay.locator('[data-confirm-message]')).toContainText('Port ge-');
    await expect(onay.locator('[data-confirm-expected]')).toHaveText('10.222.111.0/24');

    const kaydet = onay.locator('[data-confirm-accept]');
    await expect(kaydet).toBeDisabled();

    await onay.locator('[data-confirm-input]').fill('10.222.111.0/25');
    await expect(kaydet).toBeDisabled();

    await onay.locator('[data-confirm-input]').fill('10.222.111.0/24');

    // Yazılı onay tek başına yetmez: gerekçe de zorunludur.
    await expect(kaydet).toBeDisabled();

    await onay.locator('[data-confirm-reason]').fill('Yeni müşteri bloğu');
    await expect(kaydet).toBeEnabled();

    await page.screenshot({ path: `tests/Browser/shots/adresleme-onay-${test.info().project.name}.png` });

    // Canlı envantere kayıt bırakılmaz.
    await onay.locator('[data-confirm-cancel]').first().click();
    await expect(onay).toBeHidden();
});

test('prefix list yöntemi omurgadan seçilir, erişim switchi sorulmaz', async ({ page }) => {
    await girisYap(page);
    await page.goto('/adresleme');
    await page.getByRole('button', { name: 'Yeni blok' }).click();

    // Yöntem seçilmeden ne omurga ne de yönteme özel alanlar sorulur.
    await expect(page.locator('#blok-omurga')).toBeHidden();
    await expect(page.locator('#blok-erisim')).toBeHidden();

    await page.selectOption('#blok-tur', 'prefix_list');

    // Prefix list omurgada durur; erişim switchi bu yöntemde hiç görünmez.
    await expect(page.locator('#blok-omurga')).toBeVisible();
    await expect(page.locator('#blok-erisim')).toBeHidden();

    // Liste omurga seçilene kadar sorulmaz.
    await expect(page.locator('#blok-prefix-list')).toBeHidden();
    await page.selectOption('#blok-omurga', { index: 1 });
    await expect(page.locator('#blok-prefix-list')).toBeVisible();

    // Anons edilen prefix /24'ten dar olamaz.
    await expect(page.locator('#prefix_length option[value="29"]')).toBeDisabled();
    await expect(page.locator('#prefix_length option[value="24"]')).toBeEnabled();

    await page.screenshot({ path: `tests/Browser/shots/adresleme-prefix-list-${test.info().project.name}.png` });

    await page.keyboard.press('Escape');
});

test('bgp genel bakışı salt okunurdur', async ({ page }) => {
    await girisYap(page);
    await page.goto('/bgp');
    await expect(page.locator('.column__head .eyebrow').first()).toHaveText('BGP komşuları');

    // Komşular cihazdan eşitlenir; buradan elle kayıt açılmaz.
    await expect(page.getByRole('button', { name: 'BGP oluştur' })).toHaveCount(0);

    await page.screenshot({ path: `tests/Browser/shots/bgp-${test.info().project.name}.png`, fullPage: true });
});

test('bgp yönetimi switch ekranındadır', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'BGP' }).click();
    await page.waitForURL(/bgp$/);

    // İlk sütun gezinme sütunudur; başlık asıl sütundadır.
    await expect(page.locator('.column--fill .column__head .eyebrow')).toHaveText('BGP');

    // Cihaz AS numarası olmadan BGP'yi kabul etmiyor; ekran onu ilk adım yapar.
    await page.getByRole('button', { name: 'AS numarası' }).click();
    await expect(page.locator('#as-numarasi')).toBeVisible();
    await page.keyboard.press('Escape');

    await page.screenshot({ path: `tests/Browser/shots/switch-bgp-${test.info().project.name}.png`, fullPage: true });
});

test('anons ekranı dil rozetlerini ve geçmişi gösterir', async ({ page }) => {
    await girisYap(page);
    await page.goto('/prefix-anonsu');

    await expect(page.getByText('Gönderim geçmişi')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Alıcıları yönet' })).toBeVisible();

    // Alıcı yönetimi ayrı sayfada; kim hangi dilde alacak orada belirlenir.
    await page.getByRole('link', { name: 'Alıcıları yönet' }).click();
    await page.waitForURL(/alicilar$/);
    await expect(page.getByRole('button', { name: 'Alıcı ekle' })).toBeVisible();

    await page.screenshot({ path: `tests/Browser/shots/anons-gecmis-${test.info().project.name}.png`, fullPage: true });
});

test('switch ekleme ve düzenleme penceresi', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    await page.getByRole('button', { name: 'Yeni switch' }).click();

    const ekle = page.locator('#switch-ekle');
    await expect(ekle).toBeVisible();
    await expect(ekle.locator('#yeni-ad')).toBeVisible();
    await page.keyboard.press('Escape');

    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await page.getByRole('button', { name: 'Düzenle' }).click();

    const duzenle = page.locator('#switch-duzenle');
    await expect(duzenle).toBeVisible();
    // Mevcut değerler dolu gelmeli.
    await expect(duzenle.locator('#duzenle-ad')).not.toHaveValue('');
    await page.screenshot({ path: `tests/Browser/shots/switch-duzenle-${test.info().project.name}.png` });

    await page.keyboard.press('Escape');
    await expect(duzenle).toBeHidden();
});

test('detay panelindeki eylemler yan yana durur', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === 'mobil', 'telefonda panel zaten tam genişlikte');

    await girisYap(page);
    await page.goto('/sunucular');

    // Sunucu yoksa sınanacak detay paneli de yok.
    const satirlar = page.locator('a.server-row, a.grid-row');
    test.skip(await satirlar.count() === 0, 'envanterde sunucu yok');

    await satirlar.first().click();

    const ayak = page.locator('.detail__foot');
    await expect(ayak).toBeVisible();

    // İki düğme aynı satırda olmalı; üst üste binmemeli.
    const ustler = await ayak.locator('.btn').evaluateAll(
        (dugmeler) => dugmeler.map((d) => Math.round(d.getBoundingClientRect().top)),
    );

    expect(ustler.length).toBeGreaterThan(1);
    expect(new Set(ustler).size, 'düğmeler alt alta dizilmiş').toBe(1);

    await page.screenshot({ path: `tests/Browser/shots/detay-ayak-${testInfo.project.name}.png` });
});

test('vlan sayfası port sayısını gösterir', async ({ page }, testInfo) => {
    await girisYap(page);
    await page.goto('/switchler');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    /*
     * Bağlantı ADRESİNDEN seçilir: "VoIP VLAN" sekmesi eklendikten sonra
     * "VLAN" metni iki girdiye birden uyuyor ve etiket metni sayaç rozetiyle
     * birlikte geldiği için tam eşleşme de kırılgan.
     */
    await page.locator('.column .row-item[href$="/vlanlar"]').click();
    await page.waitForURL(/vlanlar$/);

    await page.screenshot({ path: `tests/Browser/shots/vlanlar-${testInfo.project.name}.png`, fullPage: false });

    const satir = page.locator('.vlan-row').nth(1);
    await expect(satir).toBeVisible();
});

/**
 * Firewall ekranı: kural yazmak yalnızca yöneticinin işidir.
 *
 * Test yönetici hesabıyla giriyor, o yüzden düğmeler görünmeli; yetkinin
 * sunucu tarafı FirewallWriteTest'te sınanıyor.
 */
test('firewall ekranı filtreleri ve bağlandıkları yeri gösterir', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'Firewall' }).click();
    await page.waitForURL(/firewall$/);

    await expect(page.locator('.column--fill .column__head .eyebrow')).toHaveText('Firewall');

    await page.screenshot({ path: `tests/Browser/shots/firewall-${test.info().project.name}.png`, fullPage: true });
});

test('firewall filtresi ekleme penceresi açılır', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');

    // Filtreleri olan bir switch aranır; hepsinde bulunmayabilir.
    const adresler = await page.locator('a.switch-row').evaluateAll(
        (baglantilar) => baglantilar.map((b) => b.getAttribute('href')),
    );

    for (const adres of adresler) {
        await page.goto(`${adres}/firewall`);

        const dugme = page.locator('[data-modal-open="filtre-ekle"]');

        if (await dugme.count()) {
            await dugme.click();

            const pencere = page.locator('#filtre-ekle');
            await expect(pencere).toBeVisible();
            await expect(pencere.locator('#fw-ad')).toBeVisible();
            await expect(pencere.locator('[name="protocols[]"]').first()).toBeVisible();

            await page.keyboard.press('Escape');
            await expect(pencere).toBeHidden();

            return;
        }
    }

    test.skip(true, 'firewall yazma yetkisi olan switch yok');
});

/**
 * Terim satırları sürüklenebilir olmalı.
 *
 * Gerçek sürükleme YAPILMAZ: bırakma anı cihaza commit atar ve görsel test
 * canlı switchin filtresini yeniden sıralardı. Burada yalnızca imkânın
 * sunulduğu doğrulanır; sıralamanın kendisi sunucu testlerinde sınanıyor.
 */
test('firewall terimleri sürüklenebilir', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');

    const adresler = await page.locator('a.switch-row').evaluateAll(
        (baglantilar) => baglantilar.map((b) => b.getAttribute('href')),
    );

    for (const adres of adresler) {
        await page.goto(`${adres}/firewall`);

        const satirlar = page.locator('[data-sira-kimlik]');

        if (await satirlar.count()) {
            await expect(satirlar.first()).toHaveAttribute('draggable', 'true');
            await expect(page.locator('[data-sira]').first()).toHaveAttribute('data-sira', /siralama$/);

            // DOM kimliği sıralı birincil anahtar TAŞIMAZ; uuid olmalı.
            const kimlik = await satirlar.first().getAttribute('data-sira-kimlik');
            expect(kimlik).toMatch(/^[0-9a-f-]{36}$/);

            return;
        }
    }

    test.skip(true, 'sürüklenebilir terim bulunamadı');
});

test('ayar ekranı durum sayfası ve bölüm sayfaları', async ({ page }) => {
    await girisYap(page);
    await page.goto('/ayarlar');

    // Giriş sayfası durum özetidir; ayar alanı burada yoktur.
    await expect(page.locator('.panel__title').first()).toHaveText('Durum');
    await expect(page.locator('#netconf__confirm_minutes')).toHaveCount(0);

    /*
     * Özet ile tablo AYNI hizada başlayıp biter. Tablo hücrelerinin kendi
     * yatay boşluğu var; panelin gövde boşluğuyla üst üste binerse tablo
     * içeride başlıyor ve iki blok kaymış duruyordu.
     */
    const ilkHucre = page.locator('.table tbody td').first();
    const sonHucre = page.locator('.table tbody tr').first().locator('td').last();

    const ozet = await page.locator('.facts__key').first().boundingBox();
    const deger = await page.locator('.facts__value').first().boundingBox();
    const hucre = await ilkHucre.boundingBox();
    const kapanis = await sonHucre.boundingBox();

    // Hücrenin kendi iç boşluğu hesaba katılır: karşılaştırılan şey kutuların
    // değil, METNİN başladığı ve bittiği yerdir.
    const solBosluk = await ilkHucre.evaluate((el) => parseFloat(getComputedStyle(el).paddingLeft));
    const sagBosluk = await sonHucre.evaluate((el) => parseFloat(getComputedStyle(el).paddingRight));

    expect(Math.abs(ozet.x - (hucre.x + solBosluk))).toBeLessThanOrEqual(1);
    expect(Math.abs((deger.x + deger.width) - (kapanis.x + kapanis.width - sagBosluk))).toBeLessThanOrEqual(1);

    await page.screenshot({ path: `tests/Browser/shots/ayarlar-${test.info().project.name}.png`, fullPage: true });

    // Bölümler şeritten açılır ve her biri kendi sayfasıdır.
    await page.locator('.tabs__link', { hasText: 'Cihaz erişimi' }).click();
    await page.waitForURL(/\/ayarlar\/cihaz$/);
    await expect(page.locator('#netconf__confirm_minutes')).toBeVisible();

    await page.locator('.tabs__link', { hasText: 'İzleme sistemi' }).click();
    await page.waitForURL(/\/ayarlar\/izleme$/);

    // Jeton alanı hiçbir zaman değer taşımaz.
    await expect(page.locator('#zabbix__api_token')).toHaveValue('');

    await page.screenshot({ path: `tests/Browser/shots/ayarlar-bolum-${test.info().project.name}.png`, fullPage: true });
});

test('satır eylemleri yan yana durur', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    const ilk = await page.locator('a.switch-row').first().getAttribute('href');
    await page.goto(`${ilk}/politikalar`);

    const eylemler = page.locator('.row-actions').first();
    test.skip(await eylemler.count() === 0, 'ekranda eylem satırı yok');

    /*
     * Düzenle düğmesi ile silme formu aynı hizada olmalı. Silme kendi
     * formundadır ve form blok seviyesindedir; kapsayıcı flex değilse
     * "Düzenle" üstte, "Sil" altında kalır.
     */
    const ustler = await eylemler.locator('> *').evaluateAll(
        (cocuklar) => cocuklar.map((c) => Math.round(c.getBoundingClientRect().top)),
    );

    test.skip(ustler.length < 2, 'tek eylem var');
    expect(new Set(ustler).size).toBe(1);
});

test('terim satırlarının sütunları hizalı', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    const ilk = await page.locator('a.switch-row').first().getAttribute('href');
    await page.goto(`${ilk}/firewall`);

    const adlar = page.locator('.term-row__ad');
    test.skip(await adlar.count() < 2, 'ekranda iki terim yok');

    /*
     * Terim adları AYNI sütunda başlar. Satır bir ızgaradır; esnek bir
     * "space-between" satırında her ad kendi metin uzunluğuna göre kayar.
     */
    const sollar = await adlar.evaluateAll(
        (ogeler) => ogeler.map((o) => Math.round(o.getBoundingClientRect().left)),
    );

    expect(new Set(sollar).size).toBe(1);
});

test('onay penceresi gerekçe ister', async ({ page }) => {
    await girisYap(page);
    await page.goto('/kullanicilar');
    await page.getByRole('link', { name: 'Yeni kullanıcı' }).click();
    await page.waitForURL(/kullanicilar\/create/);

    await page.fill('#name', 'Deneme Kullanici');
    await page.fill('#email', `deneme-${Date.now()}@ornek.test`);
    // Üst menüdeki çıkış düğmesi de bir submit'tir; formun kendi düğmesi hedeflenir.
    await page.locator('form[data-confirm] button[type="submit"]').first().click();

    const pencere = page.locator('[data-confirm-dialog]');
    await expect(pencere).toBeVisible();

    /*
     * Gerekçe yazılmadan onaylanamaz: denetimde "kim ne yaptı" kadar
     * "neden yaptı" da sorulur ve kontrol sunucuda da vardır.
     */
    const onayla = pencere.locator('[data-confirm-accept]');
    await expect(onayla).toBeDisabled();

    await pencere.locator('[data-confirm-reason]').fill('Görsel test');
    await expect(onayla).toBeEnabled();

    await pencere.locator('[data-confirm-cancel]').first().click();
    await expect(pencere).toBeHidden();
});

test('config ekranında alanlar hedefe göre değişir', async ({ page }) => {
    await girisYap(page);
    await page.goto('/config');

    // Başlangıç: envanterdeki cihaz seçili, dış cihaz alanları gizli.
    await expect(page.locator('#hedef-switch')).toBeVisible();
    await expect(page.locator('#hedef-ip')).toBeHidden();

    await page.selectOption('#hedef-tur', 'external');

    /*
     * Dış cihaz seçilince envanter listesi kaybolur ve adres istenir.
     * Gizlenen alanların girdileri devre dışı kalır; aksi hâlde form iki
     * hedefi birden gönderir.
     */
    await expect(page.locator('#hedef-switch')).toBeHidden();
    await expect(page.locator('#hedef-ip')).toBeVisible();
    await expect(page.locator('#hedef-switch')).toBeDisabled();

    await page.selectOption('#kaynak', 'template');
    await expect(page.locator('#metin')).toBeHidden();
    await expect(page.locator('#s-ip')).toBeVisible();
});

test('adres seçicide "elle gir" kutuyu açar', async ({ page }) => {
    await girisYap(page);
    await page.goto('/config');
    await page.selectOption('#kaynak', 'template');

    // Blok yoksa seçici hiç çizilmez, alanın kimliğini elle giriş kutusu taşır.
    const liste = page.locator('select#s-ip');
    test.skip(await liste.count() === 0, 'Adresleme’de yönetim bloğu yok; seçici listesiz çiziliyor');

    /*
     * Liste ile elle giriş kutusu aynı adı taşır ve her an yalnızca birinde
     * "name" bulunur; ikisi birden gönderilirse sunucuya iki değer gider.
     */
    const kutu = page.locator('#s-ip-elle');
    await expect(kutu).toBeHidden();

    await liste.selectOption('__elle__');
    await expect(kutu).toBeVisible();
    await expect(kutu).toHaveAttribute('name', 'management_address');
    await expect(liste).not.toHaveAttribute('name', /.+/);

    await liste.selectOption('');
    await expect(kutu).toBeHidden();
    await expect(liste).toHaveAttribute('name', 'management_address');
});

test('salt okunur pencerede kaydet düğmesi olmaz', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');
    const ilk = await page.locator('a.switch-row').first().getAttribute('href');
    await page.goto(`${ilk}/bgp`);

    const acici = page.locator('[data-modal-open^="alinan-"]').first();
    test.skip(await acici.count() === 0, 'komşu yok');

    await acici.click();
    const pencere = page.locator('.modal:not([hidden])').first();
    await expect(pencere).toBeVisible();

    /*
     * Alınan prefixler penceresi yalnızca okur. Ortak altlık bileşeninde
     * "gönderme yok" sentineli false'tur; null geçilirse Blade @props
     * varsayılanı "??" ile uygular ve pencereye "Kaydet" düğmesi düşer.
     */
    await expect(pencere.locator('.modal__footer button')).toHaveCount(1);
    await expect(pencere.locator('.modal__footer button')).toHaveText('Kapat');
});

test('onay penceresi yazılacak değeri olduğu gibi gösterir', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');

    const ad = await page.locator('a.switch-row .switch-row__ad').first().innerText();
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);

    /*
     * Cihaz ayarları kendi ekranında toplandı; onay penceresi oradan açılır.
     * Bağlantı adıyla aranmaz: üst şeritte de "Ayarlar" var ve o GENEL
     * ayarlara gider.
     */
    await page.goto(`${page.url()}/ayarlar`);

    /*
     * Yazılı doğrulama İSTEYEN form seçilir. "İlk data-confirm formu"
     * yazılı doğrulaması olmayan bir form (ör. saat eşitleme) olabilir;
     * o zaman test ürünü değil kendi varsayımını ölçer.
     */
    await page.locator('.panel form[data-confirm-verify]').first()
        .locator('button[type="submit"]').first().click();

    const pencere = page.locator('[data-confirm-dialog]');
    await expect(pencere).toBeVisible();

    /*
     * Alan etiketleri büyük harfe çevrilir; yazılması istenen değer
     * ÇEVRİLMEZ. Çevrilirse pencere "TEST1 yazın" der ama karşılaştırma
     * büyük/küçük harfe duyarlı olduğu için o değer kabul edilmez —
     * kullanıcıya yazamayacağı bir şey yazdırılmış olur.
     */
    await expect(pencere.locator('[data-confirm-expected]')).toHaveText(ad.trim());

    // Gösterilen değer yazıldığında onay açılmalı.
    await pencere.locator('[data-confirm-reason]').fill('Görsel test');
    await pencere.locator('[data-confirm-input]').fill(ad.trim());
    await expect(pencere.locator('[data-confirm-accept]')).toBeEnabled();

    await pencere.locator('[data-confirm-cancel]').first().click();
});

test('cihaz hesapları paneli panel hesabını korur', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');

    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await page.goto(`${page.url()}/ayarlar`);

    const panel = page.locator('.panel', { hasText: 'Cihaz hesapları' });
    await expect(panel).toBeVisible();

    const satir = panel.locator('.ayar-row').first();
    await expect(satir).toBeVisible();

    /*
     * Panelin bağlandığı hesap işaretlenir ve SİLİNEMEZ: cihaz silmeyi
     * kabul ediyor, sonrasında panel bir daha bağlanamıyor.
     */
    const panelHesabi = panel.locator('.ayar-row', { hasText: 'panel' }).first();

    if (await panelHesabi.count()) {
        await panelHesabi.locator('button').click();
        const pencere = page.locator('.modal[data-modal]:not([hidden])').first();
        await expect(pencere).toBeVisible();
        await expect(pencere.locator('.btn--danger')).toHaveCount(0);
        await expect(pencere.locator('.alert--warning')).toBeVisible();
        await pencere.locator('[data-modal-close]').first().click();
    }

    // Bölme kendi içinde kayar; ekran görüntüsü baştan alınsın.
    await page.locator('.column__body').last().evaluate((el) => el.scrollTo(0, 0));
    await page.screenshot({
        path: `tests/Browser/shots/cihaz-hesaplari-${test.info().project.name}.png`,
        fullPage: true,
    });
});

test('cihaz parolaları ekranı akışı anlatır ve onay ister', async ({ page }) => {
    await girisYap(page);
    await page.goto('/ayarlar/parolalar');

    /*
     * Panel BAŞLIĞIYLA seçilir, metniyle değil: biçim panelinin alt yazısı da
     * "toplu parola değişiminde..." diyor ve metin araması ikisini birden
     * yakalar.
     */
    const toplu = page.locator('.panel').filter({
        has: page.locator('.panel__title', { hasText: 'Toplu parola değişimi' }),
    });

    await expect(toplu).toBeVisible();

    // Sıra güvenliğin kendisidir; kullanıcı düğmeye basmadan önce görmeli.
    await expect(toplu.getByText('kurtarma hesabı açılır')).toBeVisible();

    /*
     * Üretilecek parolanın biçimi AYNI sayfada: biçimi seçtiğin yer ile
     * sonucunu gördüğün yer aynı olmalı. Şeritte ayrı bir sekme yok.
     */
    await expect(page.locator('.panel__title', { hasText: 'Üretilecek parolanın biçimi' })).toBeVisible();
    await expect(page.locator('#credentials__rotation__length')).toBeVisible();
    await expect(page.locator('.tabs__link', { hasText: /^Cihaz parolası$/ })).toHaveCount(0);

    /*
     * Toplu değişim onay penceresi açar ve yazılı onay ister. Pencere
     * kapatılır: bu test cihaza HİÇBİR ŞEY yazmaz.
     */
    await page.locator('button[form="toplu-parola"]').click();

    const pencere = page.locator('[data-confirm-dialog]');
    await expect(pencere).toBeVisible();
    await expect(pencere.locator('[data-confirm-expected]')).toHaveText('TÜM CİHAZLAR');
    await expect(pencere.locator('[data-confirm-accept]')).toBeDisabled();

    await pencere.locator('[data-confirm-cancel]').first().click();
    await expect(pencere).toBeHidden();

    await page.locator('.column__body, .page__inner').last().evaluate((el) => el.scrollTo(0, 0));
    await page.screenshot({
        path: `tests/Browser/shots/cihaz-parolalari-${test.info().project.name}.png`,
        fullPage: true,
    });
});

test('roller ekranı hazır yetkileri gösterir', async ({ page }) => {
    await girisYap(page);
    await page.goto('/ayarlar/roller');

    await expect(page.locator('.panel', { hasText: 'Roller' })).toBeVisible();

    // Varsayılan rol tek olmalı; ekranda işaretli durur.
    await expect(page.locator('.ayar-row', { hasText: 'varsayılan' })).toHaveCount(1);

    await page.locator('.ayar-row a').filter({ hasText: 'network' }).click();
    await page.waitForURL(/roller\/[0-9a-f-]{36}$/);

    // Yönetici olmayan rolde şablon matrisi görünür.
    await expect(page.locator('.panel', { hasText: 'Hazır yetkiler' })).toBeVisible();
    await expect(page.locator('.rol-kaynak').first()).toBeVisible();

    await page.screenshot({ path: `tests/Browser/shots/rol-${test.info().project.name}.png`, fullPage: true });
});

test('api jetonları ekranı açılır', async ({ page }) => {
    await girisYap(page);
    await page.goto('/ayarlar/api');

    await expect(page.locator('.panel', { hasText: 'Jetonlar' })).toBeVisible();

    // Pencere yetki matrisini taşır; jeton kendi listesiyle sınırlıdır.
    await page.locator('[data-modal-open="jeton-ekle"]').click();
    const pencere = page.locator('#jeton-ekle');
    await expect(pencere).toBeVisible();
    await expect(pencere.locator('.rol-kaynak').first()).toBeVisible();
    await pencere.locator('[data-modal-close]').first().click();

    await page.screenshot({ path: `tests/Browser/shots/api-jetonlari-${test.info().project.name}.png`, fullPage: true });
});

test('cihaz parolası istek üzerine gösterilir', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');

    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await page.goto(`${page.url()}/ayarlar`);

    const kutu = page.locator('[data-sir]').first();
    await expect(kutu).toBeVisible();

    /*
     * Parola sayfa kaynağında HİÇ bulunmaz: maskeli durur ve ancak göz
     * simgesine basıldığında ayrı bir istekle gelir.
     */
    await expect(kutu.locator('[data-sir-deger]')).toHaveText('••••••••');

    await kutu.locator('[data-sir-goster]').click();
    await expect(kutu.locator('[data-sir-deger]')).not.toHaveText('••••••••');

    /*
     * Gösterilen değer DÜZ paroladır, özet değil: yalnızca harf ve rakam
     * taşır ve kopyalanabilir. Özet gösterilirse değer kullanılamaz; test
     * değerin biçimini kilitler.
     */
    const deger = await kutu.locator('[data-sir-deger]').innerText();
    expect(deger).toMatch(/^[A-Za-z0-9]+$/);
    expect(deger.length).toBeGreaterThanOrEqual(8);

    // Kopyalama yalnızca değer açıkken görünür.
    await expect(kutu.locator('[data-sir-kopyala]')).toBeVisible();

    // Yeniden basınca gizlenir ve kopyalama düğmesi de kaybolur.
    await kutu.locator('[data-sir-goster]').click();
    await expect(kutu.locator('[data-sir-deger]')).toHaveText('••••••••');
    await expect(kutu.locator('[data-sir-kopyala]')).toBeHidden();
});

test('elle parola penceresi cihaza yazar', async ({ page }) => {
    await girisYap(page);
    await page.goto('/ayarlar/parolalar');

    const satir = page.locator('.ayar-row', { hasText: 'test' }).first();
    await satir.locator('button', { hasText: 'Elle' }).click();

    const pencere = page.locator('.modal[data-modal]:not([hidden])').first();
    await expect(pencere).toBeVisible();

    /*
     * "Yalnızca panele kaydet" diye bir seçenek YOK: panelin bildiği ile
     * cihazdaki ayrı ayrı düzenlenebilseydi iki doğruluk kaynağı olurdu.
     * Pencerede yalnızca parola sorulur ve değer cihaza yazılır.
     */
    await expect(pencere.locator('select[name="mode"]')).toHaveCount(0);
    await expect(pencere.locator('input[name="password"]')).toBeVisible();
    await expect(pencere.locator('input[name="password_confirmation"]')).toBeVisible();
    await expect(pencere.locator('.modal__footer button[type="submit"]')).toHaveText('Cihaza yaz');

    await pencere.locator('[data-modal-close]').first().click();
});

test('arama kutusu tek çerçeve çizer', async ({ page }) => {
    await girisYap(page);

    for (const adres of ['/switchler', '/kullanicilar', '/sunucular', '/kayitlar']) {
        await page.goto(adres);

        /*
         * Görsel çerçeveyi (.filter) TEK öğe taşır. Hem forma hem içindeki
         * etikete verildiğinde iç içe iki çerçeve çiziliyor ve ekranda iki
         * arama kutusu varmış gibi görünüyordu.
         */
        const icIce = await page.locator('.filter .filter').count();

        expect(icIce, `${adres} → iç içe arama çerçevesi`).toBe(0);
    }
});

/*
 * Cihaz işlem kayıtları: cihazın CEVABINI gösteren ekran. Etkinlik kaydı
 * "kim ne yaptı"yı söyler; burası "cihaz ne dedi".
 */
test('hata kayıtları ekranı açılır', async ({ page }) => {
    await girisYap(page);
    await page.goto('/kayitlar');

    // Etkinlik kaydından buraya bir yol olmalı; ekran keşfedilebilir kalsın.
    await page.locator('a', { hasText: 'Hata kayıtları' }).first().click();
    await page.waitForURL(/\/kayitlar\/hata-kayitlari/);

    await expect(page.locator('.column__head .eyebrow')).toHaveText('Hata kayıtları');

    // Süzgeçler: sonuç, işlem türü ve cihaz.
    await expect(page.locator('select[name="status"]')).toBeVisible();
    await expect(page.locator('select[name="operation"]')).toBeVisible();

    await page.screenshot({
        path: `tests/Browser/shots/hata-kayitlari-${test.info().project.name}.png`,
        fullPage: true,
    });
});

/*
 * Fırtına denetimi: spanning tree ekranıyla aynı yerde durur çünkü ikisi de
 * port korumasıdır.
 */
test('fırtına denetimi paneli spanning tree ekranında', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');

    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await page.goto(`${page.url()}/spanning-tree`);

    const panel = page.locator('.panel', { hasText: 'Fırtına denetimi' });
    await expect(panel).toBeVisible();

    // Ekleme penceresi eşiğin iki biçimini de sunar: yüzde ve kbps.
    await panel.locator('[data-modal-open="firtina-ekle"]').click();
    const pencere = page.locator('#firtina-ekle');
    await expect(pencere).toBeVisible();
    await expect(pencere.locator('input[name="level"]')).toBeVisible();
    await expect(pencere.locator('input[name="bandwidth"]')).toBeVisible();
    await pencere.locator('[data-modal-close]').first().click();

    await page.screenshot({
        path: `tests/Browser/shots/firtina-denetimi-${test.info().project.name}.png`,
        fullPage: true,
    });
});

/*
 * Statik route penceresi artık bütün alanları taşıyor: ECMP için tekrarlı
 * atlama satırları, yedek atlamalar, preference/metric/tag ve seçenekler.
 */
test('route penceresi ecmp ve seçenekleri sunar', async ({ page }) => {
    await girisYap(page);
    await page.goto('/switchler');

    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switchler\/[0-9a-f-]{36}$/);
    await page.goto(`${page.url()}/routelar`);

    await page.locator('[data-modal-open="route-ekle"]').first().click();
    const pencere = page.locator('#route-ekle');
    await expect(pencere).toBeVisible();

    // ECMP: "Atlama ekle" ikinci bir satır açar.
    const oncesi = await pencere.locator('[data-repeat="next_hops"] [data-repeat-row]').count();
    await pencere.locator('[data-repeat="next_hops"] [data-repeat-add]').click();
    await expect(pencere.locator('[data-repeat="next_hops"] [data-repeat-row]')).toHaveCount(oncesi + 1);

    await expect(pencere.locator('input[name="preference"]')).toBeVisible();
    await expect(pencere.locator('input[name="tag"]')).toBeVisible();
    await expect(pencere.locator('input[name="flags[]"]').first()).toBeAttached();

    await page.screenshot({
        path: `tests/Browser/shots/route-penceresi-${test.info().project.name}.png`,
        fullPage: true,
    });
});
