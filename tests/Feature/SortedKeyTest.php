<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * SIRALI BİRİNCİL ANAHTAR DIŞARI ÇIKMAZ.
 *
 * Adres çubuğundaki, form değerindeki ya da DOM kimliğindeki 1, 2, 3 hem kaç
 * kaydınız olduğunu söyler hem de komşu kaydı denemeye davet eder. Kural
 * CLAUDE.md'de yazılı ama tek bir unutulmuş `->id` ile sessizce delinebilir.
 *
 * Bu yüzden kural KAYNAKTA sınanır: görünümler model anahtarı basamaz.
 * Bilinçli bir istisna gerekirse satırın sonuna "// sirali-anahtar-istisna"
 * yazılır ve gerekçesi yorumda durur.
 */
final class SortedKeyTest extends TestCase
{
    public function test_gorunumler_sirali_anahtar_basmaz(): void
    {
        $found = [];

        foreach ($this->views() as $file) {
            foreach (file($file) ?: [] as $no => $row) {
                if (preg_match('/->id\b(?!entity)/', $row) !== 1) {
                    continue;
                }

                /* Oturumdaki kişinin kendi kimliği karşılaştırmadır, çıktı değil. */
                if (str_contains($row, 'user()->id') || str_contains($row, 'auth()->id')) {
                    continue;
                }

                if (str_contains($row, 'sirali-anahtar-istisna')) {
                    continue;
                }

                $found[] = str_replace(base_path().'/', '', $file).':'.($no + 1);
            }
        }

        $this->assertSame([], $found, 'Görünümde model anahtarı basılıyor; uuid kullanın.');
    }

    /** @return array<int, string> */
    private function views(): array
    {
        $files = [];

        $browser = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($browser as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
