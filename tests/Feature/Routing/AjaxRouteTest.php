<?php

declare(strict_types=1);

namespace Tests\Feature\Routing;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tarayıcıdan çağrılan uçların gerçekten var olduğunu ve test edildiğini
 * güvenceye alır.
 *
 * JavaScript'in çağırdığı bir adres (ör. /api/ip-addresses/{id}) rota olarak
 * tanımlı değilse hata yalnızca kullanıcı tıklayınca ortaya çıkar.
 */
final class AjaxRouteTest extends TestCase
{
    public function test_every_ajax_route_has_a_test(): void
    {
        $suite = $this->read(base_path('tests'));

        foreach ($this->ajaxRouteNames() as $name) {
            $this->assertStringContainsString(
                $name,
                $suite,
                "{$name} ucunun testi yok; tarayıcıdan çağrılan her uç test edilmeli.",
            );
        }
    }

    public function test_every_endpoint_referenced_by_blade_resolves(): void
    {
        $names = collect(Router::getRoutes())->map(fn (Route $route): ?string => $route->getName())->filter()->all();

        foreach ($this->bladeRouteNames() as $name) {
            $this->assertContains($name, $names, "Blade {$name} rotasını çağırıyor ama böyle bir rota yok.");
        }
    }

    /**
     * @return array<int, string>
     */
    private function ajaxRouteNames(): array
    {
        return collect(Router::getRoutes())
            ->map(fn (Route $route): ?string => $route->getName())
            ->filter(fn (?string $name): bool => $name !== null && Str::startsWith($name, 'ajax.'))
            ->values()
            ->all();
    }

    /**
     * Blade dosyalarındaki route('...') çağrılarının adları.
     *
     * @return array<int, string>
     */
    private function bladeRouteNames(): array
    {
        preg_match_all(
            "/route\(\s*'([a-z0-9._-]+)'/i",
            $this->read(base_path('resources/views')),
            $matches,
        );

        return array_unique($matches[1]);
    }

    private function test_sources(): string
    {
        return $this->read(base_path('tests'));
    }

    private function read(string $directory): string
    {
        $contents = '';

        foreach (File::allFiles($directory) as $file) {
            if (in_array($file->getExtension(), ['php', 'js'], true)) {
                $contents .= $file->getContents();
            }
        }

        return $contents;
    }
}
