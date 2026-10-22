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
        $exempt = RequireActionReason::ignoredRoutes();
        $missing = [];

        foreach ($this->postForms() as [$route, $file, $body]) {
            if ($this->collectsReason($body) || in_array($route, $exempt, true)) {
                continue;
            }

            // Oturum açmadan yapılan uçlardan gerekçe istenmez.
            if (! $this->requiresAuth($route)) {
                continue;
            }

            $missing[] = "{$route} ({$file})";
        }

        $this->assertSame(
            [],
            $missing,
            "Bu formlar gerekçe toplamıyor ve muafiyet listesinde de yok; düğmeleri sessizce çalışmaz:\n  "
            .implode("\n  ", $missing),
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
        $exempt = $this->ignoredPaths();
        $missing = [];

        foreach ($this->postFetches() as [$path, $file, $body]) {
            // Gövdesinde gerekçe taşıyan çağrı zaten kuralı sağlıyor.
            if (str_contains($body, 'reason')) {
                continue;
            }

            if (in_array($path, $exempt, true)) {
                continue;
            }

            $missing[] = "{$path} ({$file})";
        }

        $this->assertSame(
            [],
            $missing,
            "Bu fetch çağrıları gerekçe göndermiyor ve uçları muaf değil; sessizce 422 alırlar:\n  "
            .implode("\n  ", $missing),
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
        $paths = [];

        foreach (RequireActionReason::ignoredRoutes() as $name) {
            $route = Route::getRoutes()->getByName($name);

            if ($route !== null) {
                $paths[] = '/'.ltrim($route->uri(), '/');
            }
        }

        return $paths;
    }

    /**
     * resources/js içindeki POST fetch çağrıları.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function postFetches(): array
    {
        $calls = [];

        foreach ($this->sourceFiles(resource_path('js'), '.js') as $file) {
            $content = (string) file_get_contents($file);

            // fetch('/yol', { ... method: 'POST' ... })
            preg_match_all(
                "/fetch\(\s*['\"`]([^'\"`]+)['\"`]\s*,\s*(\{.*?\n\s*\})/s",
                $content,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                if (! str_contains($match[2], "method: 'POST'") && ! str_contains($match[2], 'method: "POST"')) {
                    continue;
                }

                // Değişken taşıyan adresler statik olarak çözülemez.
                if (str_contains($match[1], '$')) {
                    continue;
                }

                $calls[] = [$match[1], basename($file), $match[2]];
            }
        }

        return $calls;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function postForms(): array
    {
        $forms = [];
        $root = resource_path('views');

        foreach ($this->bladeFiles($root) as $file) {
            $content = (string) file_get_contents($file);

            preg_match_all('/<form[^>]*method="POST"[^>]*>(.*?)<\/form>/s', $content, $matches);

            foreach ($matches[0] as $body) {
                if (preg_match("/route\('([^']+)'/", $body, $route) !== 1) {
                    continue;
                }

                $forms[] = [$route[1], basename($file), $body];
            }
        }

        return $forms;
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
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), $suffix)) {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }

    private function collectsReason(string $body): bool
    {
        foreach (self::COLLECTS_REASON as $mark) {
            if (str_contains($body, $mark)) {
                return true;
            }
        }

        return false;
    }

    private function requiresAuth(string $routeName): bool
    {
        $route = Route::getRoutes()->getByName($routeName);

        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $between) {
            if (is_string($between) && str_starts_with($between, 'auth')) {
                return true;
            }
        }

        return false;
    }
}
