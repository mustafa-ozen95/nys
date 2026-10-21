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
    private const IZGARA_SINIFLARI = [
        'switch-row', 'port-row', 'server-row', 'block-row',
        'bgp-row', 'peer-row', 'community-row', 'vlan-row', 'ip-row',
        'term-row', 'recipient-row',
    ];

    public function test_izgara_satiri_esnek_satir_siniflariyla_karistirilmaz(): void
    {
        $hatalar = [];

        foreach ($this->bladeDosyalari() as $dosya) {
            foreach ($this->classNitelikleri(file_get_contents($dosya)) as $satirNo => $siniflar) {
                if ($this->hemEsnekHemIzgara($siniflar)) {
                    $hatalar[] = basename($dosya).':'.$satirNo.' → '.$siniflar;
                }
            }
        }

        $this->assertSame([], $hatalar, implode("\n", [
            'Izgara satırı "row" ile birlikte kullanılmış; ızgara şablonu uygulanmaz.',
            ...$hatalar,
        ]));
    }

    public function test_her_izgara_satirinin_bir_sablonu_vardir(): void
    {
        $css = implode("\n", array_map(
            file_get_contents(...),
            glob(__DIR__.'/../../../resources/css/components/*.css') ?: [],
        ));

        foreach (self::IZGARA_SINIFLARI as $sinif) {
            $this->assertMatchesRegularExpression(
                '/\.'.preg_quote($sinif, '/').'\s*\{[^}]*grid-template-columns/s',
                $css,
                "{$sinif} bir ızgara sınıfı ama grid-template-columns tanımlamıyor.",
            );
        }
    }

    private function hemEsnekHemIzgara(string $siniflar): bool
    {
        $liste = preg_split('/\s+/', trim($siniflar)) ?: [];

        if (! in_array('row', $liste, true)) {
            return false;
        }

        return array_intersect($liste, self::IZGARA_SINIFLARI) !== [];
    }

    /**
     * @return array<int, string> satır numarası => class değeri
     */
    private function classNitelikleri(string $icerik): array
    {
        $bulunan = [];

        foreach (explode("\n", $icerik) as $sira => $satir) {
            if (preg_match('/class="([^"{}]*)"/', $satir, $eslesme) === 1) {
                $bulunan[$sira + 1] = $eslesme[1];
            }
        }

        return $bulunan;
    }

    /**
     * @return list<string>
     */
    private function bladeDosyalari(): array
    {
        $dizin = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../../../resources/views'),
        );

        $dosyalar = [];

        foreach ($dizin as $dosya) {
            if ($dosya->isFile() && str_ends_with($dosya->getFilename(), '.blade.php')) {
                $dosyalar[] = $dosya->getPathname();
            }
        }

        return $dosyalar;
    }
}
