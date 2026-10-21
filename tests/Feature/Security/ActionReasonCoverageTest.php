<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\RequireActionReason;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Gerekçe kapsamı: DÜĞMELERİN SESSİZCE ÖLMESİNİ ENGELLER.
 *
 * Oturum açmış kullanıcının durum değiştiren her isteği gerekçe ister
 * (RequireActionReason). Gerekçe toplamayan bir form yazıldığında istek
 * doğrulama hatasıyla geri döner ve kullanıcı yalnızca "bir şey olmadı"
 * görür — hata sayfası bile çıkmaz.
 *
 * Ayrıntı düzeyi, commit farkı ya da bildirimleri okundu işaretleme gibi
 * küçük düğmelerde bu kolayca gözden kaçar ve diğer testler yakalamaz, çünkü
 * TestCase gerekçe ara katmanını varsayılan olarak KAPATIYOR.
 *
 * Bu test ara katmanı çalıştırmaz; KAYNAĞI okur. İki yol taranır:
 *
 *   1. Blade'deki POST formları,
 *   2. JavaScript'teki POST fetch çağrıları.
 *
 * İkincisi gereklidir: bir fetch çağrısı form olarak görünmez ve gerekçe
 * göndermiyorsa ucu fark edilmeden 422 dönebilir.
 *
 * Her ikisi de ya gerekçe toplar ya da rotası muafiyet listesinde geçer.
 * Üçüncü ihtimal yoktur.
 */
final class ActionReasonCoverageTest extends TestCase
{
    /** Gerekçenin toplandığını gösteren işaretler. */
    private const COLLECTS_REASON = ['name="reason"', 'data-confirm', 'x-ui.delete-form', 'x-ui.confirm-modal'];

    public function test_her_durum_degistiren_form_ya_gerekce_toplar_ya_muaftir(): void
    {
        $muaf = RequireActionReason::ignoredRoutes();
        $eksik = [];

        foreach ($this->postForms() as [$rota, $dosya, $govde]) {
            if ($this->collectsReason($govde) || in_array($rota, $muaf, true)) {
                continue;
            }

            // Oturum açmadan yapılan uçlardan gerekçe istenmez.
            if (! $this->requiresAuth($rota)) {
                continue;
            }

            $eksik[] = "{$rota} ({$dosya})";
        }

        $this->assertSame(
            [],
            $eksik,
            "Bu formlar gerekçe toplamıyor ve muafiyet listesinde de yok; düğmeleri sessizce çalışmaz:\n  "
            .implode("\n  ", $eksik),
        );
    }

    /**
     * JavaScript'ten yapılan POST çağrıları da aynı kurala tabidir.
     *
     * Bir fetch çağrısı gerekçe göndermiyorsa ucun muaf olması gerekir;
     * değilse istek 422 döner ve ekran sessizce hiçbir şey yapmaz.
     */
    public function test_javascriptten_yapilan_post_cagrilari_da_kapsanir(): void
    {
        $muaf = $this->ignoredPaths();
        $eksik = [];

        foreach ($this->postFetches() as [$yol, $dosya, $govde]) {
            // Gövdesinde gerekçe taşıyan çağrı zaten kuralı sağlıyor.
            if (str_contains($govde, 'reason')) {
                continue;
            }

            if (in_array($yol, $muaf, true)) {
                continue;
            }

            $eksik[] = "{$yol} ({$dosya})";
        }

        $this->assertSame(
            [],
            $eksik,
            "Bu fetch çağrıları gerekçe göndermiyor ve uçları muaf değil; sessizce 422 alırlar:\n  "
            .implode("\n  ", $eksik),
        );
    }

    /**
     * Muaf rotaların ADRESLERİ. Fetch çağrısında rota adı değil yol geçtiği
     * için karşılaştırma adres üzerinden yapılır.
     *
     * @return list<string>
     */
    private function ignoredPaths(): array
    {
        $yollar = [];

        foreach (RequireActionReason::ignoredRoutes() as $ad) {
            $rota = Route::getRoutes()->getByName($ad);

            if ($rota !== null) {
                $yollar[] = '/'.ltrim($rota->uri(), '/');
            }
        }

        return $yollar;
    }

    /**
     * resources/js içindeki POST fetch çağrıları.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function postFetches(): array
    {
        $cagrilar = [];

        foreach ($this->sourceFiles(resource_path('js'), '.js') as $dosya) {
            $icerik = (string) file_get_contents($dosya);

            // fetch('/yol', { ... method: 'POST' ... })
            preg_match_all(
                "/fetch\(\s*['\"`]([^'\"`]+)['\"`]\s*,\s*(\{.*?\n\s*\})/s",
                $icerik,
                $eslesmeler,
                PREG_SET_ORDER,
            );

            foreach ($eslesmeler as $eslesme) {
                if (! str_contains($eslesme[2], "method: 'POST'") && ! str_contains($eslesme[2], 'method: "POST"')) {
                    continue;
                }

                // Değişken taşıyan adresler statik olarak çözülemez.
                if (str_contains($eslesme[1], '$')) {
                    continue;
                }

                $cagrilar[] = [$eslesme[1], basename($dosya), $eslesme[2]];
            }
        }

        return $cagrilar;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function postForms(): array
    {
        $formlar = [];
        $kok = resource_path('views');

        foreach ($this->bladeFiles($kok) as $dosya) {
            $icerik = (string) file_get_contents($dosya);

            preg_match_all('/<form[^>]*method="POST"[^>]*>(.*?)<\/form>/s', $icerik, $eslesmeler);

            foreach ($eslesmeler[0] as $govde) {
                if (preg_match("/route\('([^']+)'/", $govde, $rota) !== 1) {
                    continue;
                }

                $formlar[] = [$rota[1], basename($dosya), $govde];
            }
        }

        return $formlar;
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(string $path): array
    {
        return $this->sourceFiles($path, '.blade.php');
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(string $path, string $suffix): array
    {
        $dosyalar = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), $suffix)) {
                $dosyalar[] = $item->getPathname();
            }
        }

        return $dosyalar;
    }

    private function collectsReason(string $body): bool
    {
        foreach (self::COLLECTS_REASON as $isaret) {
            if (str_contains($body, $isaret)) {
                return true;
            }
        }

        return false;
    }

    private function requiresAuth(string $routeName): bool
    {
        $rota = Route::getRoutes()->getByName($routeName);

        if ($rota === null) {
            return false;
        }

        foreach ($rota->gatherMiddleware() as $ara) {
            if (is_string($ara) && str_starts_with($ara, 'auth')) {
                return true;
            }
        }

        return false;
    }
}
