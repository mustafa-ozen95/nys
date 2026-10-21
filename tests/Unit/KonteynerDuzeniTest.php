<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Kurulum Compose standardında: veritabanı ve redis ayrı servis, panel yalnız HTTP.
 *
 * Asıl kurallar: imajda MariaDB ve redis SUNUCUSU yok (istemci var: bekleme
 * ve taşıma için); nginx yalnız 80'i dinler, TLS'i vekil bitirir; başlatıcı
 * veritabanını ortamdan alır ve gelene kadar bekler; compose'ta db ve redis
 * servisleri var, HTTP portu yayınlanmaz, sırlar dosyaya yazılmaz.
 *
 * NYS'ye özgü: kuyruk işçileri, zamanlayıcı ve uzak oturumlar (ttyd,
 * Guacamole) panelin konteynerinde kalır; veritabanının adı ve kullanıcısı
 * nsm.
 */
class KonteynerDuzeniTest extends TestCase
{
    private const KOK = __DIR__.'/../..';

    private const AD = 'nsm';

    private static function oku(string $yol): string
    {
        return (string) file_get_contents(self::KOK.'/'.$yol);
    }

    public function test_imajda_veritabani_ve_redis_sunucusu_yok(): void
    {
        $d = self::oku('docker/Dockerfile');

        $this->assertStringNotContainsString('mariadb-server', $d);
        $this->assertStringNotContainsString('redis-server', $d);
        $this->assertStringContainsString('mariadb-client', $d);
        $this->assertStringNotContainsString('/var/lib/mysql', $d);
        $this->assertMatchesRegularExpression('/^EXPOSE 80$/m', $d);
    }

    public function test_nginx_yalniz_80_dinler(): void
    {
        $n = self::oku('docker/rootfs/etc/nginx/conf.d/'.self::AD.'.conf');

        $this->assertStringContainsString('listen 80 default_server;', $n);
        // Bekçi alt isteğindeki "SERVER_PORT 443" bir dinleyici değil:
        // istemcinin vekile HTTPS ile geldiğini Laravel'e söyler.
        $this->assertDoesNotMatchRegularExpression('/^\s*listen\s+(?!80 default_server;)/m', $n);
        $this->assertStringNotContainsString('ssl_', $n);
    }

    public function test_baslatici_veritabanini_ortamdan_alir_ve_bekler(): void
    {
        $b = self::oku('docker/rootfs/usr/local/bin/'.self::AD.'-entrypoint');

        $this->assertStringContainsString(': "${DB_HOST:=db}"', $b);
        $this->assertStringContainsString(': "${REDIS_HOST:=redis}"', $b);
        $this->assertStringContainsString('mariadb_bekle "${VT_BEKLE_CNF}"', $b);
        $this->assertStringContainsString('vt ortam-istemci', $b);
        // Varsayılanlar alt süreçlere (dc-veritabani, artisan) geçmeli.
        $this->assertSame(1, preg_match('/^export ((?:[^\n]*\\\\\n)*[^\n]*)$/m', $b, $m));
        $this->assertMatchesRegularExpression('/\bDB_HOST\b/', $m[1]);
        $this->assertMatchesRegularExpression('/\bREDIS_HOST\b/', $m[1]);
        foreach (['mariadbd', 'mariadb-install-db', 'redis-server', 'openssl req', '--protocol=socket'] as $yok) {
            $this->assertStringNotContainsString($yok, $b, $yok);
        }
        // Panele özgü bölüm korunur.
        $this->assertStringContainsString('[program:guacd]', $b);
    }

    public function test_kuyruk_iscileri_panelin_konteynerinde_kalir(): void
    {
        $s = self::oku('docker/rootfs/etc/supervisor/supervisord.conf');

        foreach (['[program:isci-yazma]', '[program:isci-genel]', '[program:zamanlayici]', '[program:yeniden-baslat]'] as $var) {
            $this->assertStringContainsString($var, $s, $var);
        }
        $this->assertStringNotContainsString('mariadb', $s);
    }

    public function test_compose_standart_duzende(): void
    {
        $c = self::oku('docker-compose.yml');

        foreach (["\n  panel:\n", "\n  db:\n", "\n  redis:\n", 'image: mariadb:', 'replicas: ${VT_KOPYA:-1}', 'expose: ["80"]', 'internal: ${AG_YALITIMI:-false}',
            'container_name: ${ONEK:-nys}', 'MARIADB_DATABASE: ${DB_DATABASE:-nsm}', 'MARIADB_USER: ${DB_USERNAME:-nsm}', 'stop_grace_period: 1h'] as $var) {
            $this->assertStringContainsString($var, $c, $var);
        }
        $this->assertStringNotContainsString('env_file', $c);
        $this->assertStringNotContainsString(':80"', $c, 'HTTP portu yayınlanmaz');
        $this->assertStringNotContainsString('external: true', $c);
        $this->assertDoesNotMatchRegularExpression('/^\s+- [A-Z_]*(PASSWORD|SIR|KEY|JETON|SECRET|TOKEN)[A-Z_]*=/m', $c, 'sır compose dosyasına yazılmaz');
        $this->assertFileExists(self::KOK.'/.env.example');
        $this->assertFileDoesNotExist(self::KOK.'/docker/compose.yml');
        $this->assertFileDoesNotExist(self::KOK.'/docker/nys.conf.example');
    }

    /**
     * Günlük sır taşımaz: dağıtım platformları konteyner günlüğünü
     * arayüzde gösterebilir; üretilen APP_KEY ve ilk parola yalnız 0600
     * dosyada durur, günlükte dosyanın yeri yazar.
     */
    public function test_baslatici_sirri_gunluge_basmaz(): void
    {
        $b = self::oku('docker/rootfs/usr/local/bin/'.self::AD.'-entrypoint');

        $this->assertDoesNotMatchRegularExpression('/^\s*(uyari|bilgi|hata)\b[^\n]*\$\{?(APP_KEY|ADMIN_PASSWORD|DB_PASSWORD|GUACAMOLE_SECRET)\b/m', $b);
        $this->assertStringContainsString('cat /uygulama/storage/app/app-key.txt', $b);
        $this->assertStringContainsString('cat /uygulama/storage/app/ilk-parola.txt', $b);
    }

    public function test_baslaticinin_varsayilan_veritabani_compose_ile_ayni(): void
    {
        $b = self::oku('docker/rootfs/usr/local/bin/'.self::AD.'-entrypoint');

        $this->assertStringContainsString(': "${DB_DATABASE:=nsm}"', $b);
        $this->assertStringContainsString(': "${DB_USERNAME:=nsm}"', $b);
    }
}
