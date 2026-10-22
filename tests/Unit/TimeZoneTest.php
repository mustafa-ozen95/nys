<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Panel ve veritabanı oturumu konteynerin saatinde.
 *
 * Asıl kural: uzak ya da yönetilen bir veritabanı UTC'de çalışsa bile oturum
 * iç veritabanıyla aynı dilimde açılır; yoksa TIMESTAMP sütunları taşımadan
 * sonra dilim farkı kadar kaymış okunur.
 */
class TimeZoneTest extends TestCase
{
    private const LAUNCHER = __DIR__.'/../../docker/rootfs/usr/local/bin/nsm-entrypoint';

    /** @var array<string, string|false> */
    private array $previous = [];

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) {
            unset($_ENV[$name], $_SERVER[$name]);
            if ($value !== false) {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }

        parent::tearDown();
    }

    private function environment(string $name, string $value): void
    {
        $this->previous[$name] = $_ENV[$name] ?? false;
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    public function test_panel_ve_oturum_dilimi_ortamdan_okunur(): void
    {
        $this->environment('APP_TIMEZONE', 'Europe/Istanbul');
        $this->environment('DB_TIMEZONE', '+03:00');

        $this->assertSame('Europe/Istanbul', (require __DIR__.'/../../config/app.php')['timezone']);

        $connections = (require __DIR__.'/../../config/database.php')['connections'];
        $this->assertSame('+03:00', $connections['mysql']['timezone']);
        $this->assertSame('+03:00', $connections['mariadb']['timezone']);
    }

    public function test_baslatici_oturum_dilimini_konteynerin_saatinden_verir(): void
    {
        $launcher = (string) file_get_contents(self::LAUNCHER);

        $this->assertStringContainsString(': "${DB_TIMEZONE:=$(date +%:z)}"', $launcher);
        $this->assertStringContainsString('DB_USERNAME DB_PASSWORD DB_TIMEZONE \\', $launcher);
    }
}
