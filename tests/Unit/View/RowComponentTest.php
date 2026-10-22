<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Satırlar standart bileşeni kullanır.
 *
 * Bir satır ya esnek bir satırdır (`row`) ya da ızgaradır
 * (`grid-row` + kendi ızgara sınıfı). İkisi birden verildiğinde ızgara
 * şablonu HİÇ uygulanmaz — `.row` display:flex verir — ve geriye
 * `space-between` kalır: sütunlar her satırda kendi metin uzunluğuna göre
 * kayar. Hata sessizdir; ekran "çalışıyor" görünür.
 *
 * Firewall ve politika terimleri tam olarak böyle yazılmıştı ve üstelik
 * kendilerine ait olmayan BGP ızgarasını taşıyorlardı.
 *
 * Bu denetim ucuzdur ve tarayıcı açmaz: kural bir kez bozulduğunda her
 * ekranda bozulur, o yüzden kaynak metinde yakalanır.
 */
final class RowComponentTest extends TestCase
{
    /** Izgara şablonu tanımlayan satır sınıfları. */
    private const GRID_CLASSES = [
        'switch-row', 'port-row', 'server-row', 'block-row',
        'bgp-row', 'peer-row', 'community-row', 'vlan-row', 'ip-row',
        'term-row', 'recipient-row',
    ];

    public function test_izgara_satiri_esnek_satir_siniflariyla_karistirilmaz(): void
    {
        $errors = [];

        foreach ($this->bladeFiles() as $file) {
            foreach ($this->classAttributes(file_get_contents($file)) as $rowNo => $classes) {
                if ($this->bothFlexibleAndGrid($classes)) {
                    $errors[] = basename($file).':'.$rowNo.' → '.$classes;
                }
            }
        }

        $this->assertSame([], $errors, implode("\n", [
            'Izgara satırı "row" ile birlikte kullanılmış; ızgara şablonu uygulanmaz.',
            ...$errors,
        ]));
    }

    public function test_her_izgara_satirinin_bir_sablonu_vardir(): void
    {
        $css = implode("\n", array_map(
            file_get_contents(...),
            glob(__DIR__.'/../../../resources/css/components/*.css') ?: [],
        ));

        foreach (self::GRID_CLASSES as $class) {
            $this->assertMatchesRegularExpression(
                '/\.'.preg_quote($class, '/').'\s*\{[^}]*grid-template-columns/s',
                $css,
                "{$class} bir ızgara sınıfı ama grid-template-columns tanımlamıyor.",
            );
        }
    }

    private function bothFlexibleAndGrid(string $classes): bool
    {
        $liste = preg_split('/\s+/', trim($classes)) ?: [];

        if (! in_array('row', $liste, true)) {
            return false;
        }

        return array_intersect($liste, self::GRID_CLASSES) !== [];
    }

    /**
     * @return array<int, string> satır numarası => class değeri
     */
    private function classAttributes(string $content): array
    {
        $found = [];

        foreach (explode("\n", $content) as $order => $row) {
            if (preg_match('/class="([^"{}]*)"/', $row, $match) === 1) {
                $found[$order + 1] = $match[1];
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../../../resources/views'),
        );

        $files = [];

        foreach ($directory as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
