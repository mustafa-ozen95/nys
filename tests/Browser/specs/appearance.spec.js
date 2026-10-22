import { expect, test } from '@playwright/test';

import { performLogin } from '../support/login.js';

/*
 * Panel HER EKRANDA aynı adı taşır.
 *
 * Ad tek kaynaktan gelir — hangisi olduğu .env'de verilir. Test bu yüzden
 * sabit bir ad beklemez; giriş ekranındaki adı üst çubukta arar.
 */
test('giriş ekranı', async ({ page }) => {
    await page.goto('/login');
    const loginName = (await page.locator('.gate__mark').innerText()).trim();

    await performLogin(page);

    /* Aynı ad üst çubukta da durmalı: iki ekran, tek kaynak. */
    await expect(page.locator('.topbar__mark')).toHaveText(loginName);

    await page.goto('/login');
    await page.screenshot({ path: `tests/Browser/shots/giris-${test.info().project.name}.png`, fullPage: true });
});

test('genel bakış', async ({ page }) => {
    await performLogin(page);
    await expect(page.locator('.column__head .eyebrow')).toHaveText('Switchler');
    await page.screenshot({ path: `tests/Browser/shots/genel-bakis-${test.info().project.name}.png`, fullPage: true });
});

test('genel bakış — açık tema', async ({ page }) => {
    await performLogin(page);
    await page.click('[data-theme-toggle]');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await page.screenshot({ path: `tests/Browser/shots/genel-bakis-acik-${test.info().project.name}.png`, fullPage: true });
});

test('switch listesi', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    await expect(page.locator('.column__head .eyebrow')).toHaveText('Switchler');
    await page.screenshot({ path: `tests/Browser/shots/switchler-${test.info().project.name}.png`, fullPage: true });
});

test('switch detayı', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await expect(page.locator('.panel__title').first()).toBeVisible();
    await page.screenshot({ path: `tests/Browser/shots/switch-detay-${test.info().project.name}.png`, fullPage: true });
});

test('yetki matrisi', async ({ page }) => {
    await performLogin(page);
    await page.goto('/users');
    await page.locator('a[href*="/permissions"]').first().click();
    await expect(page.locator('.tabs')).toBeVisible();
    await page.screenshot({ path: `tests/Browser/shots/yetki-matrisi-${test.info().project.name}.png`, fullPage: true });
});

test('port listesi ve trafik grafiği', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);

    // Üst menüde de "Portlar" var; buradaki nesne sütunundaki bağlantıdır.
    await page.locator('.column .row-item', { hasText: 'Portlar' }).click();
    await page.waitForURL(/\/ports$/);
    // .port-row başlık satırına da uyar ve o mobilde gizlidir; gerçek satır aranır.
    await expect(page.locator('button.port-row').first()).toBeVisible();

    await page.screenshot({ path: `tests/Browser/shots/portlar-${test.info().project.name}.png`, fullPage: true });

    // Bir porta tıklayınca trafik paneli açılmalı.
    await page.locator('button.port-row').first().click();
    await expect(page.locator('[data-traffic-panel]')).toBeVisible();
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `tests/Browser/shots/trafik-${test.info().project.name}.png`, fullPage: true });
});

test('sunucu listesi ve formu', async ({ page }) => {
    await performLogin(page);
    await page.goto('/servers');
    await expect(page.locator('.column__head .eyebrow')).toHaveText('Sunucular');
    await page.screenshot({ path: `tests/Browser/shots/sunucular-${test.info().project.name}.png`, fullPage: true });

    // Sunucu ekleme ayrı sayfa değil, pencere: liste görünürken doldurulur.
    await page.getByRole('button', { name: 'Yeni sunucu' }).click();
    const dialogBox = page.locator('#server-add');
    await expect(dialogBox).toBeVisible();
    await expect(dialogBox.locator('#short_name')).toBeVisible();
    await page.screenshot({ path: `tests/Browser/shots/sunucu-formu-${test.info().project.name}.png`, fullPage: true });
});

test('etkinlik kayıtları', async ({ page }) => {
    await performLogin(page);
    await page.goto('/logs');
    await expect(page.locator('.column__head .eyebrow')).toHaveText('Etkinlik kayıtları');
    await page.screenshot({ path: `tests/Browser/shots/kayitlar-${test.info().project.name}.png`, fullPage: true });
});

test('prefix anonsu — önizleme', async ({ page }) => {
    await performLogin(page);
    await page.goto('/prefix-announcement');
    await page.fill('#prefixes', '192.0.2.0/24\n198.18.0.0/22');

    // Alıcılar veritabanından yönetilir; canlıda tanımlı olmayabilir.
    const recipients = page.locator('.recipient-list input[type="checkbox"]');

    if (await recipients.count()) {
        await recipients.first().check();
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
    await performLogin(page);
    await page.goto('/prefix-announcement');

    const recipients = page.locator('.recipient-list input[type="checkbox"]');

    // Canlıda alıcı tanımlı olmayabilir; veri üretmeyiz, atlarız.
    test.skip(await recipients.count() === 0, 'Tanımlı alıcı yok.');

    await page.locator('select#asn').selectOption('__elle__');
    await page.locator('#asn-elle').fill('AS64496');

    await page.fill('#prefixes', '192.0.2.0/24\n198.51.100.0/24\n203.0.113.0/24');
    await recipients.first().check();
    await page.getByRole('button', { name: 'Önizle' }).click();

    const panel = page.locator('.announce-permission');
    await expect(panel).toBeVisible();

    await expect(panel.getByText('RPKI ROA (RIPE)').first()).toBeVisible();
    await expect(panel.getByText('IRR route nesnesi (RADB)').first()).toBeVisible();

    // Temiz blok: iki kaynak da onaylar, eksik satırı hiç çizilmez.
    const clean = panel.locator('.announce-permission-row', { hasText: '192.0.2.0/24' });
    await expect(clean.locator('.badge--green')).toHaveCount(2);
    await expect(clean.locator('.announce-permission-row__reason')).toHaveCount(0);

    // Eksik blok: gönderimi durdurmaz ama eksiği YAZILIR ve engel gibi kızarmaz.
    const missing = panel.locator('.announce-permission-row', { hasText: '198.51.100.0/24' });
    await expect(missing.locator('.badge--green')).toHaveCount(1);
    await expect(missing.locator('.badge--red')).toHaveCount(1);
    await expect(missing.locator('.announce-permission-row__reason')).toBeVisible();
    await expect(missing.locator('.announce-permission-row__reason--blocked')).toHaveCount(0);

    // Kanıtsız blok: iki kaynak da reddeder ve gerekçe engel olarak işaretlenir.
    const stopped = panel.locator('.announce-permission-row', { hasText: '203.0.113.0/24' });
    await expect(stopped.locator('.badge--red')).toHaveCount(2);
    await expect(stopped.locator('.announce-permission-row__reason--blocked')).toBeVisible();

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
    await performLogin(page);
    await page.goto('/prefix-announcement');

    const list = page.locator('select#asn');
    const box = page.locator('#asn-elle');

    await expect(box).toBeHidden();
    await expect(list).toHaveAttribute('name', 'asn');

    await list.selectOption('__elle__');
    await expect(box).toBeVisible();
    await expect(box).toHaveAttribute('name', 'asn');
    await expect(list).not.toHaveAttribute('name', /.+/);

    await list.selectOption('');
    await expect(box).toBeHidden();
    await expect(list).toHaveAttribute('name', 'asn');
});

/**
 * Önizleme durumu da yatay kaymaz.
 *
 * Çerçevenin geneli tasarim.spec.js'te ölçülüyor ama oradaki tur yalnızca GET
 * sayfalarını geziyor; yetki paneli ancak formu göndererek çizilir ve gerekçe
 * satırı uzun metin taşır.
 */
test('anons yetkisi paneli sayfayı yatay kaydırmaz', async ({ page }) => {
    await performLogin(page);
    await page.goto('/prefix-announcement');

    const recipients = page.locator('.recipient-list input[type="checkbox"]');
    test.skip(await recipients.count() === 0, 'Tanımlı alıcı yok.');

    await page.fill('#prefixes', '198.51.100.0/24');
    await recipients.first().check();
    await page.getByRole('button', { name: 'Önizle' }).click();
    await expect(page.locator('.announce-permission')).toBeVisible();

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );

    expect(overflow).toBeLessThanOrEqual(1);
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
    await performLogin(page);

    await page.locator('[data-notification-open]').click();
    const panel = page.locator('[data-notification-panel]');
    await expect(panel).toBeVisible();

    /*
     * İSTEĞİN SONUCU beklenir, sonra iddia edilir.
     *
     * İlk yazımda tıklamadan hemen sonra "kırmızı bildirim yok" deniyordu ve
     * test muafiyet kaldırılmışken bile GEÇİYORDU: yokluk iddiası, henüz
     * tamamlanmamış isteği yarışta geçiyor. Ölçülen şey artık ucun kendisi.
     */
    const request = page.waitForResponse(
        (response) => response.url().includes('/notifications/seen') && response.request().method() === 'POST',
    );

    await panel.locator('[data-notification-read]').click();

    const response = await request;

    // 422 = gerekçe ara katmanı ucu reddetti; düğme sessizce ölmüş demektir.
    expect(response.status()).toBe(200);

    await expect(page.locator('.toast--danger')).toHaveCount(0);
    await expect(page.locator('[data-notification-counter]')).toBeHidden();
});

test('port seçilince liste görünür kalır', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'Portlar' }).click();
    await page.waitForURL(/\/ports$/);

    const rows = page.locator('button.port-row');
    await expect(rows.first()).toBeVisible();

    await rows.first().click();

    // Grafik yüklenirken durum yazısı listeyi örtmemeli.
    await expect(rows.first()).toBeVisible();
    await expect(page.locator('[data-traffic-panel]')).toBeVisible();

    await page.locator('.chart-pair').waitFor({ state: 'visible' });
    await expect(rows.first()).toBeVisible();
});

test('bir grafiğin üzerine gelince diğeri de aynı noktayı gösterir', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'masaüstü', 'imleçle etkileşim yalnızca masaüstünde anlamlı');

    await performLogin(page);
    await page.goto('/switches');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'Portlar' }).click();
    await page.waitForURL(/\/ports$/);

    // Trafiği olan bir port seçilir; boş seride vurgulanacak nokta olmaz.
    await page.locator('button.port-row').first().click();
    await page.locator('.chart-pair').waitFor({ state: 'visible' });
    await page.waitForTimeout(500);

    const hasData = await page.evaluate(() => window.__trafficCharts?.length === 2
        && window.__trafficCharts[0].data.labels.length > 2);

    test.skip(!hasData, 'bu portta gösterilecek trafik verisi yok');

    // İlk grafiğin ortasına gelinir.
    const box = await page.locator('.chart-pair__item').first().locator('canvas').boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.waitForTimeout(200);

    const active = await page.evaluate(
        () => window.__trafficCharts.map((g) => g.getActiveElements().length),
    );

    // İkisinde de bir nokta vurgulu olmalı.
    expect(active[0]).toBeGreaterThan(0);
    expect(active[1], 'ikinci grafik eşleşmedi').toBeGreaterThan(0);
});

test('genel bakışta switche tıklayınca genel bakış açılır', async ({ page }) => {
    await performLogin(page);
    await page.locator('a.switch-row').first().click();

    // Portlar sayfası değil, switchin genel bakışı açılmalı.
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await expect(page.locator('.column__head .eyebrow').filter({ hasText: 'Genel bakış' })).toBeVisible();
});

test('kaydırınca tablo başlığı içeriğin altında kalmaz', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'Portlar' }).click();
    await page.waitForURL(/\/ports$/);

    const body = page.locator('.column--fill .column__body');
    await body.evaluate((el) => el.scrollTo(0, 400));
    await page.waitForTimeout(200);

    // Başlık yapışkandır; zemini saydam olursa satırlar altından görünür.
    const background = await page.locator('.port-row__head').evaluate(
        (el) => getComputedStyle(el).backgroundColor,
    );

    expect(background).not.toBe('rgba(0, 0, 0, 0)');
    expect(background).not.toBe('transparent');

    await page.screenshot({ path: `tests/Browser/shots/kaydirma-${test.info().project.name}.png`, fullPage: false });
});

test('seçili zaman aralığı görünür biçimde ayrışır', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'Portlar' }).click();
    await page.waitForURL(/\/ports$/);
    await page.locator('button.port-row').first().click();

    const selected = page.locator('[data-traffic-range][aria-pressed="true"]');
    const idle = page.locator('[data-traffic-range][aria-pressed="false"]').first();

    await expect(selected).toHaveCount(1);

    const background = (position) => position.evaluate((el) => getComputedStyle(el).backgroundColor);

    // Seçili düğmenin zemini diğerlerinden farklı olmalı.
    expect(await background(selected)).not.toBe(await background(idle));

    // Başka bir aralığa geçince seçim onunla birlikte taşınmalı.
    await page.locator('[data-traffic-range="24h"]').click();
    await expect(page.locator('[data-traffic-range="24h"]')).toHaveAttribute('aria-pressed', 'true');
    await expect(selected).toHaveCount(1);

    await page.waitForTimeout(800);
    // Grafik çizilmeden görüntü alınırsa panel boş çıkıyor.
    await page.locator('.chart-pair').waitFor({ state: 'visible' });
    await page.waitForTimeout(400);
    await page.locator('[data-traffic-panel]').screenshot({
        path: `tests/Browser/shots/aralik-${test.info().project.name}.png`,
    });
});

test('klavyeyle gezinme gerçekten çalışır', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'Portlar' }).click();
    await page.waitForURL(/\/ports$/);

    // ↑↓ satırlar arasında dolaşır.
    await page.locator('button.port-row').first().focus();
    const first = await page.evaluate(() => document.activeElement.textContent.trim().slice(0, 12));
    await page.keyboard.press('ArrowDown');
    const second = await page.evaluate(() => document.activeElement.textContent.trim().slice(0, 12));

    expect(second).not.toBe(first);

    // → seçili satırı açar: trafik paneli görünür olmalı.
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('[data-traffic-panel]')).toBeVisible();

    // "/" filtre alanına odaklanır.
    await page.keyboard.press('Escape');
    await page.locator('body').click({ position: { x: 5, y: 300 } });
    await page.keyboard.press('/');
    await expect(page.locator('.filter__input')).toBeFocused();
});

test('genel bakıştan porta tıklayınca detay açık gelir', async ({ page }) => {
    await performLogin(page);

    /*
     * "Yoğun portlar" ÖLÇÜME bağlıdır: eşiğin üstünde trafik yoksa liste hiç
     * çizilmez. Canlıda veri üretmeyiz, atlarız — atlamayan test trafiğin
     * düştüğü anda kendiliğinden kırılıyordu.
     */
    const busyPort = page.locator('.busy-row').first();
    test.skip(await busyPort.count() === 0, 'Şu an eşiğin üstünde trafik taşıyan port yok.');

    const portName = (await busyPort.locator('.busy-row__port').textContent()).trim();
    await busyPort.click();

    await page.waitForURL(/\/ports\?port=/);

    // Panel kendiliğinden açılmış ve doğru portu göstermeli.
    await expect(page.locator('[data-traffic-panel]')).toBeVisible();
    await expect(page.locator('[data-traffic-header]')).toHaveText(portName);
    await expect(page.locator(`button.port-row[aria-selected="true"]`)).toHaveCount(1);

    await page.waitForTimeout(800);
    await page.screenshot({ path: `tests/Browser/shots/porta-gecis-${test.info().project.name}.png`, fullPage: false });
});

test('sunucuya tıklayınca notu ve bilgileri açılır', async ({ page }) => {
    await performLogin(page);
    await page.goto('/servers');

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
    await page.goto('/login');

    await expect(page.getByRole('link', { name: 'Parolamı unuttum' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Kayıt ol' })).toHaveCount(0);

    await page.screenshot({ path: `tests/Browser/shots/giris-sifirlama-yok-${test.info().project.name}.png`, fullPage: true });
});

test('adresleme ekranı blokları ve kullanım durumunu gösterir', async ({ page }) => {
    await performLogin(page);
    await page.goto('/addressing');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: `tests/Browser/shots/adresleme-${test.info().project.name}.png`, fullPage: true });

    // Kesin eşleşme: form ipuçlarında da "blokları" geçiyor.
    await expect(page.getByText('Bloklar', { exact: true })).toBeVisible();
});

test('yeni blok penceresi açılır', async ({ page }, testInfo) => {
    await performLogin(page);
    await page.goto('/addressing');
    await page.getByRole('button', { name: 'Yeni blok' }).click();

    const dialogBox = page.locator('#block-add');
    await expect(dialogBox).toBeVisible();

    // Maske listeden seçilir, elle CIDR yazılmaz.
    await expect(page.locator('#prefix_length option', { hasText: '/24' })).toHaveCount(1);

    // Önce yöntem seçilir; erişim switchi ondan sonra sorulur.
    await page.selectOption('#blok-tur', 'port');
    await page.selectOption('#blok-erisim', { index: 1 });

    // Port listesi sunucudan dolar, elle yazılmaz. VLAN alanı port
    // yönteminde hiç görünmez.
    const portSelection = page.locator('#blok-port');
    await expect(portSelection).toBeVisible();
    await expect(portSelection.locator('option')).not.toHaveCount(1);
    await expect(page.locator('#blok-vlan')).toBeHidden();

    /*
     * Pencere gövdesi kaydırma gerektirmemeli.
     *
     * Telefonda bu geçerli değil: ağ, maske, amaç, başlık, yöntem, switch ve
     * port aynı ekrana sığmaz ve gövde içinde kaydırmak doğru davranıştır.
     * Masaüstü ve tablette kaydırma hâlâ bir kusurdur.
     */
    if (testInfo.project.name !== 'mobil') {
        const scroll = await page.locator('#block-add .modal__body').evaluate(
            (el) => el.scrollHeight - el.clientHeight,
        );
        expect(scroll, 'pencere kaydırma gerektiriyor').toBeLessThanOrEqual(1);
    }

    await page.screenshot({ path: `tests/Browser/shots/adresleme-blok-ekle-${test.info().project.name}.png` });

    await page.keyboard.press('Escape');
    await expect(dialogBox).toBeHidden();
});

test('blok kaydetmeden önce yazılı onay ister', async ({ page }) => {
    await performLogin(page);
    await page.goto('/addressing');
    await page.getByRole('button', { name: 'Yeni blok' }).click();

    await page.fill('#network', '10.222.111.0');
    await page.selectOption('#prefix_length', '24');
    await page.selectOption('#blok-tur', 'port');
    await page.selectOption('#blok-erisim', { index: 1 });
    await page.selectOption('#blok-port', { index: 1 });

    await page.locator('#block-add button[type="submit"]').click();

    // Kaydetmez; ne yapılacağını özetleyip bloğu tekrar yazmasını ister.
    const approval = page.locator('[data-confirm-dialog]');
    await expect(approval).toBeVisible();
    await expect(approval.locator('[data-confirm-message]')).toContainText('10.222.111.0/24');
    await expect(approval.locator('[data-confirm-message]')).toContainText('route edilecek');
    // Yol zincir olarak okunmalı; seçilen port liste yenilenirken kaybolmamalı.
    await expect(approval.locator('[data-confirm-message]')).toContainText('→');
    await expect(approval.locator('[data-confirm-message]')).toContainText('Port ge-');
    await expect(approval.locator('[data-confirm-expected]')).toHaveText('10.222.111.0/24');

    const save = approval.locator('[data-confirm-accept]');
    await expect(save).toBeDisabled();

    await approval.locator('[data-confirm-input]').fill('10.222.111.0/25');
    await expect(save).toBeDisabled();

    await approval.locator('[data-confirm-input]').fill('10.222.111.0/24');

    // Yazılı onay tek başına yetmez: gerekçe de zorunludur.
    await expect(save).toBeDisabled();

    await approval.locator('[data-confirm-reason]').fill('Yeni müşteri bloğu');
    await expect(save).toBeEnabled();

    await page.screenshot({ path: `tests/Browser/shots/adresleme-onay-${test.info().project.name}.png` });

    // Canlı envantere kayıt bırakılmaz.
    await approval.locator('[data-confirm-cancel]').first().click();
    await expect(approval).toBeHidden();
});

test('prefix list yöntemi omurgadan seçilir, erişim switchi sorulmaz', async ({ page }) => {
    await performLogin(page);
    await page.goto('/addressing');
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
    await performLogin(page);
    await page.goto('/bgp');
    await expect(page.locator('.column__head .eyebrow').first()).toHaveText('BGP komşuları');

    // Komşular cihazdan eşitlenir; buradan elle kayıt açılmaz.
    await expect(page.getByRole('button', { name: 'BGP oluştur' })).toHaveCount(0);

    await page.screenshot({ path: `tests/Browser/shots/bgp-${test.info().project.name}.png`, fullPage: true });
});

test('bgp yönetimi switch ekranındadır', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'BGP' }).click();
    await page.waitForURL(/bgp$/);

    // İlk sütun gezinme sütunudur; başlık asıl sütundadır.
    await expect(page.locator('.column--fill .column__head .eyebrow')).toHaveText('BGP');

    // Cihaz AS numarası olmadan BGP'yi kabul etmiyor; ekran onu ilk adım yapar.
    await page.getByRole('button', { name: 'AS numarası' }).click();
    await expect(page.locator('#as-number-input')).toBeVisible();
    await page.keyboard.press('Escape');

    await page.screenshot({ path: `tests/Browser/shots/switch-bgp-${test.info().project.name}.png`, fullPage: true });
});

test('anons ekranı dil rozetlerini ve geçmişi gösterir', async ({ page }) => {
    await performLogin(page);
    await page.goto('/prefix-announcement');

    await expect(page.getByText('Gönderim geçmişi')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Alıcıları yönet' })).toBeVisible();

    // Alıcı yönetimi ayrı sayfada; kim hangi dilde alacak orada belirlenir.
    await page.getByRole('link', { name: 'Alıcıları yönet' }).click();
    await page.waitForURL(/recipients$/);
    await expect(page.getByRole('button', { name: 'Alıcı ekle' })).toBeVisible();

    await page.screenshot({ path: `tests/Browser/shots/anons-gecmis-${test.info().project.name}.png`, fullPage: true });
});

test('switch ekleme ve düzenleme penceresi', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    await page.getByRole('button', { name: 'Yeni switch' }).click();

    const add = page.locator('#switch-add');
    await expect(add).toBeVisible();
    await expect(add.locator('#yeni-ad')).toBeVisible();
    await page.keyboard.press('Escape');

    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await page.getByRole('button', { name: 'Düzenle' }).click();

    const edit = page.locator('#switch-edit');
    await expect(edit).toBeVisible();
    // Mevcut değerler dolu gelmeli.
    await expect(edit.locator('#duzenle-ad')).not.toHaveValue('');
    await page.screenshot({ path: `tests/Browser/shots/switch-duzenle-${test.info().project.name}.png` });

    await page.keyboard.press('Escape');
    await expect(edit).toBeHidden();
});

test('detay panelindeki eylemler yan yana durur', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === 'mobil', 'telefonda panel zaten tam genişlikte');

    await performLogin(page);
    await page.goto('/servers');

    // Sunucu yoksa sınanacak detay paneli de yok.
    const rows = page.locator('a.server-row, a.grid-row');
    test.skip(await rows.count() === 0, 'envanterde sunucu yok');

    await rows.first().click();

    const ayak = page.locator('.detail__foot');
    await expect(ayak).toBeVisible();

    // İki düğme aynı satırda olmalı; üst üste binmemeli.
    const tops = await ayak.locator('.btn').evaluateAll(
        (buttons) => buttons.map((d) => Math.round(d.getBoundingClientRect().top)),
    );

    expect(tops.length).toBeGreaterThan(1);
    expect(new Set(tops).size, 'düğmeler alt alta dizilmiş').toBe(1);

    await page.screenshot({ path: `tests/Browser/shots/detay-ayak-${testInfo.project.name}.png` });
});

test('vlan sayfası port sayısını gösterir', async ({ page }, testInfo) => {
    await performLogin(page);
    await page.goto('/switches');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    /*
     * Bağlantı ADRESİNDEN seçilir: "VoIP VLAN" sekmesi eklendikten sonra
     * "VLAN" metni iki girdiye birden uyuyor ve etiket metni sayaç rozetiyle
     * birlikte geldiği için tam eşleşme de kırılgan.
     */
    await page.locator('.column .row-item[href$="/vlans"]').click();
    await page.waitForURL(/vlans$/);

    await page.screenshot({ path: `tests/Browser/shots/vlanlar-${testInfo.project.name}.png`, fullPage: false });

    const row = page.locator('.vlan-row').nth(1);
    await expect(row).toBeVisible();
});

/**
 * Firewall ekranı: kural yazmak yalnızca yöneticinin işidir.
 *
 * Test yönetici hesabıyla giriyor, o yüzden düğmeler görünmeli; yetkinin
 * sunucu tarafı FirewallWriteTest'te sınanıyor.
 */
test('firewall ekranı filtreleri ve bağlandıkları yeri gösterir', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await page.locator('.column .row-item', { hasText: 'Firewall' }).click();
    await page.waitForURL(/firewall$/);

    await expect(page.locator('.column--fill .column__head .eyebrow')).toHaveText('Firewall');

    await page.screenshot({ path: `tests/Browser/shots/firewall-${test.info().project.name}.png`, fullPage: true });
});

test('firewall filtresi ekleme penceresi açılır', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');

    // Filtreleri olan bir switch aranır; hepsinde bulunmayabilir.
    const addresses = await page.locator('a.switch-row').evaluateAll(
        (connections) => connections.map((b) => b.getAttribute('href')),
    );

    for (const address of addresses) {
        await page.goto(`${address}/firewall`);

        const button = page.locator('[data-modal-open="filter-add"]');

        if (await button.count()) {
            await button.click();

            const dialogBox = page.locator('#filter-add');
            await expect(dialogBox).toBeVisible();
            await expect(dialogBox.locator('#fw-name')).toBeVisible();
            await expect(dialogBox.locator('[name="protocols[]"]').first()).toBeVisible();

            await page.keyboard.press('Escape');
            await expect(dialogBox).toBeHidden();

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
    await performLogin(page);
    await page.goto('/switches');

    const addresses = await page.locator('a.switch-row').evaluateAll(
        (connections) => connections.map((b) => b.getAttribute('href')),
    );

    for (const address of addresses) {
        await page.goto(`${address}/firewall`);

        const rows = page.locator('[data-order-id]');

        if (await rows.count()) {
            await expect(rows.first()).toHaveAttribute('draggable', 'true');
            await expect(page.locator('[data-order]').first()).toHaveAttribute('data-order', /order$/);

            // DOM kimliği sıralı birincil anahtar TAŞIMAZ; uuid olmalı.
            const kimlik = await rows.first().getAttribute('data-order-id');
            expect(kimlik).toMatch(/^[0-9a-f-]{36}$/);

            return;
        }
    }

    test.skip(true, 'sürüklenebilir terim bulunamadı');
});

test('ayar ekranı durum sayfası ve bölüm sayfaları', async ({ page }) => {
    await performLogin(page);
    await page.goto('/settings');

    // Giriş sayfası durum özetidir; ayar alanı burada yoktur.
    await expect(page.locator('.panel__title').first()).toHaveText('Durum');
    await expect(page.locator('#netconf__confirm_minutes')).toHaveCount(0);

    /*
     * Özet ile tablo AYNI hizada başlayıp biter. Tablo hücrelerinin kendi
     * yatay boşluğu var; panelin gövde boşluğuyla üst üste binerse tablo
     * içeride başlıyor ve iki blok kaymış duruyordu.
     */
    const firstCell = page.locator('.table tbody td').first();
    const lastCell = page.locator('.table tbody tr').first().locator('td').last();

    const summary = await page.locator('.facts__key').first().boundingBox();
    const value = await page.locator('.facts__value').first().boundingBox();
    const cell = await firstCell.boundingBox();
    const closing = await lastCell.boundingBox();

    // Hücrenin kendi iç boşluğu hesaba katılır: karşılaştırılan şey kutuların
    // değil, METNİN başladığı ve bittiği yerdir.
    const leftGap = await firstCell.evaluate((el) => parseFloat(getComputedStyle(el).paddingLeft));
    const rightGap = await lastCell.evaluate((el) => parseFloat(getComputedStyle(el).paddingRight));

    expect(Math.abs(summary.x - (cell.x + leftGap))).toBeLessThanOrEqual(1);
    expect(Math.abs((value.x + value.width) - (closing.x + closing.width - rightGap))).toBeLessThanOrEqual(1);

    await page.screenshot({ path: `tests/Browser/shots/ayarlar-${test.info().project.name}.png`, fullPage: true });

    // Bölümler şeritten açılır ve her biri kendi sayfasıdır.
    await page.locator('.tabs__link', { hasText: 'Cihaz erişimi' }).click();
    await page.waitForURL(/\/settings\/device$/);
    await expect(page.locator('#netconf__confirm_minutes')).toBeVisible();

    await page.locator('.tabs__link', { hasText: 'İzleme sistemi' }).click();
    await page.waitForURL(/\/settings\/monitoring$/);

    // Jeton alanı hiçbir zaman değer taşımaz.
    await expect(page.locator('#zabbix__api_token')).toHaveValue('');

    await page.screenshot({ path: `tests/Browser/shots/ayarlar-bolum-${test.info().project.name}.png`, fullPage: true });
});

test('satır eylemleri yan yana durur', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    const first = await page.locator('a.switch-row').first().getAttribute('href');
    await page.goto(`${first}/policies`);

    const actions = page.locator('.row-actions').first();
    test.skip(await actions.count() === 0, 'ekranda eylem satırı yok');

    /*
     * Düzenle düğmesi ile silme formu aynı hizada olmalı. Silme kendi
     * formundadır ve form blok seviyesindedir; kapsayıcı flex değilse
     * "Düzenle" üstte, "Sil" altında kalır.
     */
    const tops = await actions.locator('> *').evaluateAll(
        (children) => children.map((c) => Math.round(c.getBoundingClientRect().top)),
    );

    test.skip(tops.length < 2, 'tek eylem var');
    expect(new Set(tops).size).toBe(1);
});

test('terim satırlarının sütunları hizalı', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    const first = await page.locator('a.switch-row').first().getAttribute('href');
    await page.goto(`${first}/firewall`);

    const names = page.locator('.term-row__name');
    test.skip(await names.count() < 2, 'ekranda iki terim yok');

    /*
     * Terim adları AYNI sütunda başlar. Satır bir ızgaradır; esnek bir
     * "space-between" satırında her ad kendi metin uzunluğuna göre kayar.
     */
    const lefts = await names.evaluateAll(
        (items) => items.map((o) => Math.round(o.getBoundingClientRect().left)),
    );

    expect(new Set(lefts).size).toBe(1);
});

test('onay penceresi gerekçe ister', async ({ page }) => {
    await performLogin(page);
    await page.goto('/users');
    await page.getByRole('link', { name: 'Yeni kullanıcı' }).click();
    await page.waitForURL(/users\/create/);

    await page.fill('#name', 'Deneme Kullanici');
    await page.fill('#email', `deneme-${Date.now()}@ornek.test`);
    // Üst menüdeki çıkış düğmesi de bir submit'tir; formun kendi düğmesi hedeflenir.
    await page.locator('form[data-confirm] button[type="submit"]').first().click();

    const dialogBox = page.locator('[data-confirm-dialog]');
    await expect(dialogBox).toBeVisible();

    /*
     * Gerekçe yazılmadan onaylanamaz: denetimde "kim ne yaptı" kadar
     * "neden yaptı" da sorulur ve kontrol sunucuda da vardır.
     */
    const confirm = dialogBox.locator('[data-confirm-accept]');
    await expect(confirm).toBeDisabled();

    await dialogBox.locator('[data-confirm-reason]').fill('Görsel test');
    await expect(confirm).toBeEnabled();

    await dialogBox.locator('[data-confirm-cancel]').first().click();
    await expect(dialogBox).toBeHidden();
});

test('config ekranında alanlar hedefe göre değişir', async ({ page }) => {
    await performLogin(page);
    await page.goto('/config');

    // Başlangıç: envanterdeki cihaz seçili, dış cihaz alanları gizli.
    await expect(page.locator('#target-switch')).toBeVisible();
    await expect(page.locator('#target-ip')).toBeHidden();

    await page.selectOption('#target-type', 'external');

    /*
     * Dış cihaz seçilince envanter listesi kaybolur ve adres istenir.
     * Gizlenen alanların girdileri devre dışı kalır; aksi hâlde form iki
     * hedefi birden gönderir.
     */
    await expect(page.locator('#target-switch')).toBeHidden();
    await expect(page.locator('#target-ip')).toBeVisible();
    await expect(page.locator('#target-switch')).toBeDisabled();

    await page.selectOption('#kaynak', 'template');
    await expect(page.locator('#metin')).toBeHidden();
    await expect(page.locator('#s-ip')).toBeVisible();
});

test('adres seçicide "elle gir" kutuyu açar', async ({ page }) => {
    await performLogin(page);
    await page.goto('/config');
    await page.selectOption('#kaynak', 'template');

    // Blok yoksa seçici hiç çizilmez, alanın kimliğini elle giriş kutusu taşır.
    const list = page.locator('select#s-ip');
    test.skip(await list.count() === 0, 'Adresleme’de yönetim bloğu yok; seçici listesiz çiziliyor');

    /*
     * Liste ile elle giriş kutusu aynı adı taşır ve her an yalnızca birinde
     * "name" bulunur; ikisi birden gönderilirse sunucuya iki değer gider.
     */
    const box = page.locator('#s-ip-elle');
    await expect(box).toBeHidden();

    await list.selectOption('__elle__');
    await expect(box).toBeVisible();
    await expect(box).toHaveAttribute('name', 'management_address');
    await expect(list).not.toHaveAttribute('name', /.+/);

    await list.selectOption('');
    await expect(box).toBeHidden();
    await expect(list).toHaveAttribute('name', 'management_address');
});

test('salt okunur pencerede kaydet düğmesi olmaz', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');
    const first = await page.locator('a.switch-row').first().getAttribute('href');
    await page.goto(`${first}/bgp`);

    const opener = page.locator('[data-modal-open^="alinan-"]').first();
    test.skip(await opener.count() === 0, 'komşu yok');

    await opener.click();
    const dialogBox = page.locator('.modal:not([hidden])').first();
    await expect(dialogBox).toBeVisible();

    /*
     * Alınan prefixler penceresi yalnızca okur. Ortak altlık bileşeninde
     * "gönderme yok" sentineli false'tur; null geçilirse Blade @props
     * varsayılanı "??" ile uygular ve pencereye "Kaydet" düğmesi düşer.
     */
    await expect(dialogBox.locator('.modal__footer button')).toHaveCount(1);
    await expect(dialogBox.locator('.modal__footer button')).toHaveText('Kapat');
});

test('onay penceresi yazılacak değeri olduğu gibi gösterir', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');

    const name = await page.locator('a.switch-row .switch-row__name').first().innerText();
    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);

    /*
     * Cihaz ayarları kendi ekranında toplandı; onay penceresi oradan açılır.
     * Bağlantı adıyla aranmaz: üst şeritte de "Ayarlar" var ve o GENEL
     * ayarlara gider.
     */
    await page.goto(`${page.url()}/settings`);

    /*
     * Yazılı doğrulama İSTEYEN form seçilir. "İlk data-confirm formu"
     * yazılı doğrulaması olmayan bir form (ör. saat eşitleme) olabilir;
     * o zaman test ürünü değil kendi varsayımını ölçer.
     */
    await page.locator('.panel form[data-confirm-verify]').first()
        .locator('button[type="submit"]').first().click();

    const dialogBox = page.locator('[data-confirm-dialog]');
    await expect(dialogBox).toBeVisible();

    /*
     * Alan etiketleri büyük harfe çevrilir; yazılması istenen değer
     * ÇEVRİLMEZ. Çevrilirse pencere "TEST1 yazın" der ama karşılaştırma
     * büyük/küçük harfe duyarlı olduğu için o değer kabul edilmez —
     * kullanıcıya yazamayacağı bir şey yazdırılmış olur.
     */
    await expect(dialogBox.locator('[data-confirm-expected]')).toHaveText(name.trim());

    // Gösterilen değer yazıldığında onay açılmalı.
    await dialogBox.locator('[data-confirm-reason]').fill('Görsel test');
    await dialogBox.locator('[data-confirm-input]').fill(name.trim());
    await expect(dialogBox.locator('[data-confirm-accept]')).toBeEnabled();

    await dialogBox.locator('[data-confirm-cancel]').first().click();
});

test('cihaz hesapları paneli panel hesabını korur', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');

    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await page.goto(`${page.url()}/settings`);

    const panel = page.locator('.panel', { hasText: 'Cihaz hesapları' });
    await expect(panel).toBeVisible();

    const row = panel.locator('.setting-row').first();
    await expect(row).toBeVisible();

    /*
     * Panelin bağlandığı hesap işaretlenir ve SİLİNEMEZ: cihaz silmeyi
     * kabul ediyor, sonrasında panel bir daha bağlanamıyor.
     */
    const panelAccount = panel.locator('.setting-row', { hasText: 'panel' }).first();

    if (await panelAccount.count()) {
        await panelAccount.locator('button').click();
        const dialogBox = page.locator('.modal[data-modal]:not([hidden])').first();
        await expect(dialogBox).toBeVisible();
        await expect(dialogBox.locator('.btn--danger')).toHaveCount(0);
        await expect(dialogBox.locator('.alert--warning')).toBeVisible();
        await dialogBox.locator('[data-modal-close]').first().click();
    }

    // Bölme kendi içinde kayar; ekran görüntüsü baştan alınsın.
    await page.locator('.column__body').last().evaluate((el) => el.scrollTo(0, 0));
    await page.screenshot({
        path: `tests/Browser/shots/cihaz-hesaplari-${test.info().project.name}.png`,
        fullPage: true,
    });
});

test('cihaz parolaları ekranı akışı anlatır ve onay ister', async ({ page }) => {
    await performLogin(page);
    await page.goto('/settings/passwords');

    /*
     * Panel BAŞLIĞIYLA seçilir, metniyle değil: biçim panelinin alt yazısı da
     * "toplu parola değişiminde..." diyor ve metin araması ikisini birden
     * yakalar.
     */
    const bulk = page.locator('.panel').filter({
        has: page.locator('.panel__title', { hasText: 'Toplu parola değişimi' }),
    });

    await expect(bulk).toBeVisible();

    // Sıra güvenliğin kendisidir; kullanıcı düğmeye basmadan önce görmeli.
    await expect(bulk.getByText('kurtarma hesabı açılır')).toBeVisible();

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
    await page.locator('button[form="bulk-password"]').click();

    const dialogBox = page.locator('[data-confirm-dialog]');
    await expect(dialogBox).toBeVisible();
    await expect(dialogBox.locator('[data-confirm-expected]')).toHaveText('TÜM CİHAZLAR');
    await expect(dialogBox.locator('[data-confirm-accept]')).toBeDisabled();

    await dialogBox.locator('[data-confirm-cancel]').first().click();
    await expect(dialogBox).toBeHidden();

    await page.locator('.column__body, .page__inner').last().evaluate((el) => el.scrollTo(0, 0));
    await page.screenshot({
        path: `tests/Browser/shots/cihaz-parolalari-${test.info().project.name}.png`,
        fullPage: true,
    });
});

test('roller ekranı hazır yetkileri gösterir', async ({ page }) => {
    await performLogin(page);
    await page.goto('/settings/roles');

    await expect(page.locator('.panel', { hasText: 'Roller' })).toBeVisible();

    // Varsayılan rol tek olmalı; ekranda işaretli durur.
    await expect(page.locator('.setting-row', { hasText: 'varsayılan' })).toHaveCount(1);

    await page.locator('.setting-row a').filter({ hasText: 'network' }).click();
    await page.waitForURL(/roles\/[0-9a-f-]{36}$/);

    // Yönetici olmayan rolde şablon matrisi görünür.
    await expect(page.locator('.panel', { hasText: 'Hazır yetkiler' })).toBeVisible();
    await expect(page.locator('.role-source').first()).toBeVisible();

    await page.screenshot({ path: `tests/Browser/shots/rol-${test.info().project.name}.png`, fullPage: true });
});

test('api jetonları ekranı açılır', async ({ page }) => {
    await performLogin(page);
    await page.goto('/settings/api');

    await expect(page.locator('.panel', { hasText: 'Jetonlar' })).toBeVisible();

    // Pencere yetki matrisini taşır; jeton kendi listesiyle sınırlıdır.
    await page.locator('[data-modal-open="token-add"]').click();
    const dialogBox = page.locator('#token-add');
    await expect(dialogBox).toBeVisible();
    await expect(dialogBox.locator('.role-source').first()).toBeVisible();
    await dialogBox.locator('[data-modal-close]').first().click();

    await page.screenshot({ path: `tests/Browser/shots/api-jetonlari-${test.info().project.name}.png`, fullPage: true });
});

test('cihaz parolası istek üzerine gösterilir', async ({ page }) => {
    await performLogin(page);
    await page.goto('/switches');

    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await page.goto(`${page.url()}/settings`);

    const box = page.locator('[data-secret]').first();
    await expect(box).toBeVisible();

    /*
     * Parola sayfa kaynağında HİÇ bulunmaz: maskeli durur ve ancak göz
     * simgesine basıldığında ayrı bir istekle gelir.
     */
    await expect(box.locator('[data-secret-value]')).toHaveText('••••••••');

    await box.locator('[data-secret-show]').click();
    await expect(box.locator('[data-secret-value]')).not.toHaveText('••••••••');

    /*
     * Gösterilen değer DÜZ paroladır, özet değil: yalnızca harf ve rakam
     * taşır ve kopyalanabilir. Özet gösterilirse değer kullanılamaz; test
     * değerin biçimini kilitler.
     */
    const value = await box.locator('[data-secret-value]').innerText();
    expect(value).toMatch(/^[A-Za-z0-9]+$/);
    expect(value.length).toBeGreaterThanOrEqual(8);

    // Kopyalama yalnızca değer açıkken görünür.
    await expect(box.locator('[data-secret-copy]')).toBeVisible();

    // Yeniden basınca gizlenir ve kopyalama düğmesi de kaybolur.
    await box.locator('[data-secret-show]').click();
    await expect(box.locator('[data-secret-value]')).toHaveText('••••••••');
    await expect(box.locator('[data-secret-copy]')).toBeHidden();
});

test('elle parola penceresi cihaza yazar', async ({ page }) => {
    await performLogin(page);
    await page.goto('/settings/passwords');

    const row = page.locator('.setting-row', { hasText: 'test' }).first();
    await row.locator('button', { hasText: 'Elle' }).click();

    const dialogBox = page.locator('.modal[data-modal]:not([hidden])').first();
    await expect(dialogBox).toBeVisible();

    /*
     * "Yalnızca panele kaydet" diye bir seçenek YOK: panelin bildiği ile
     * cihazdaki ayrı ayrı düzenlenebilseydi iki doğruluk kaynağı olurdu.
     * Pencerede yalnızca parola sorulur ve değer cihaza yazılır.
     */
    await expect(dialogBox.locator('select[name="mode"]')).toHaveCount(0);
    await expect(dialogBox.locator('input[name="password"]')).toBeVisible();
    await expect(dialogBox.locator('input[name="password_confirmation"]')).toBeVisible();
    await expect(dialogBox.locator('.modal__footer button[type="submit"]')).toHaveText('Cihaza yaz');

    await dialogBox.locator('[data-modal-close]').first().click();
});

test('arama kutusu tek çerçeve çizer', async ({ page }) => {
    await performLogin(page);

    for (const address of ['/switches', '/users', '/servers', '/logs']) {
        await page.goto(address);

        /*
         * Görsel çerçeveyi (.filter) TEK öğe taşır. Hem forma hem içindeki
         * etikete verildiğinde iç içe iki çerçeve çiziliyor ve ekranda iki
         * arama kutusu varmış gibi görünüyordu.
         */
        const nested = await page.locator('.filter .filter').count();

        expect(nested, `${address} → iç içe arama çerçevesi`).toBe(0);
    }
});

/*
 * Cihaz işlem kayıtları: cihazın CEVABINI gösteren ekran. Etkinlik kaydı
 * "kim ne yaptı"yı söyler; burası "cihaz ne dedi".
 */
test('hata kayıtları ekranı açılır', async ({ page }) => {
    await performLogin(page);
    await page.goto('/logs');

    // Etkinlik kaydından buraya bir yol olmalı; ekran keşfedilebilir kalsın.
    await page.locator('a', { hasText: 'Hata kayıtları' }).first().click();
    await page.waitForURL(/\/logs\/device-operations/);

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
    await performLogin(page);
    await page.goto('/switches');

    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await page.goto(`${page.url()}/spanning-tree`);

    const panel = page.locator('.panel', { hasText: 'Fırtına denetimi' });
    await expect(panel).toBeVisible();

    // Ekleme penceresi eşiğin iki biçimini de sunar: yüzde ve kbps.
    await panel.locator('[data-modal-open="storm-add"]').click();
    const dialogBox = page.locator('#storm-add');
    await expect(dialogBox).toBeVisible();
    await expect(dialogBox.locator('input[name="level"]')).toBeVisible();
    await expect(dialogBox.locator('input[name="bandwidth"]')).toBeVisible();
    await dialogBox.locator('[data-modal-close]').first().click();

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
    await performLogin(page);
    await page.goto('/switches');

    await page.locator('a.switch-row').first().click();
    await page.waitForURL(/\/switches\/[0-9a-f-]{36}$/);
    await page.goto(`${page.url()}/routes`);

    await page.locator('[data-modal-open="route-add"]').first().click();
    const dialogBox = page.locator('#route-add');
    await expect(dialogBox).toBeVisible();

    // ECMP: "Atlama ekle" ikinci bir satır açar.
    const before = await dialogBox.locator('[data-repeat="next_hops"] [data-repeat-row]').count();
    await dialogBox.locator('[data-repeat="next_hops"] [data-repeat-add]').click();
    await expect(dialogBox.locator('[data-repeat="next_hops"] [data-repeat-row]')).toHaveCount(before + 1);

    await expect(dialogBox.locator('input[name="preference"]')).toBeVisible();
    await expect(dialogBox.locator('input[name="tag"]')).toBeVisible();
    await expect(dialogBox.locator('input[name="flags[]"]').first()).toBeAttached();

    await page.screenshot({
        path: `tests/Browser/shots/route-penceresi-${test.info().project.name}.png`,
        fullPage: true,
    });
});
