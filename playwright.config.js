import { readFileSync } from 'node:fs';
import { defineConfig, devices } from '@playwright/test';

/**
 * Yönetici bilgileri uygulamanın .env dosyasından okunur; testlerin çalışması
 * için ayrıca ortam değişkeni geçmek gerekmez.
 */
function ortamDegeri(anahtar, varsayilan = '') {
    const satir = readFileSync(new URL('./.env', import.meta.url), 'utf8')
        .split('\n')
        .find((s) => s.startsWith(`${anahtar}=`));

    return satir ? satir.slice(anahtar.length + 1).trim().replace(/^"|"$/g, '') : varsayilan;
}

process.env.E2E_ADMIN_EMAIL ??= ortamDegeri('ADMIN_EMAIL');
process.env.E2E_ADMIN_PASSWORD ??= ortamDegeri('ADMIN_PASSWORD');

/**
 * Görsel testler. Uygulama ana vhost'ta çalışır; sertifika
 * alan adına ait olduğu için ignoreHTTPSErrors gerekmez, ancak yerel çözümleme
 * için host eşlemesi kullanılır.
 */
export default defineConfig({
    testDir: './tests/Browser/specs',
    snapshotDir: './tests/Browser/snapshots',
    outputDir: './tests/Browser/results',
    fullyParallel: true,
    reporter: [['list']],

    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'https://panel-a.ornek.test',
        ignoreHTTPSErrors: true,
        locale: 'tr-TR',
        timezoneId: 'Europe/Istanbul',
    },

    projects: [
        { name: 'masaüstü', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
        { name: 'tablet', use: { ...devices['Desktop Chrome'], viewport: { width: 834, height: 1112 } } },
        { name: 'mobil', use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 } } },
    ],
});
