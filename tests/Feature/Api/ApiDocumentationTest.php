<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Api\ApiDocumentation;
use App\Services\Authorization\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API belgesi.
 *
 * Belge rotalardan üretilir; elle yazılmış ikinci bir uç listesi yoktur.
 * Buradaki testler o bağın kopmadığını kilitler: belgesiz bir uç eklemek ya
 * da silinen bir ucun belgesini bırakmak testi kırar.
 */
final class ApiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Belgesiz uç OLAMAZ: dışarıya açtığımız bir adresin ne yaptığı ve hangi
     * yetkiyi istediği yazılı olmalı.
     */
    public function test_her_ucun_belgesi_var(): void
    {
        $belgesiz = array_filter(
            app(ApiDocumentation::class)->endpoints(),
            fn (array $endpoint): bool => ! $endpoint['documented'],
        );

        $this->assertSame(
            [],
            array_column($belgesiz, 'name'),
            'Belgesiz API ucu var; config/api.php içindeki "endpoints" listesine eklenmeli.',
        );
    }

    /**
     * Silinen bir ucun belgesi kalmamalı: belge var olmayan bir adresi
     * anlatmamalı.
     */
    public function test_karsiligi_olmayan_belge_yok(): void
    {
        $this->assertSame([], app(ApiDocumentation::class)->orphans());
    }

    /**
     * Belgedeki yetkiler paneldeki yetki sözlüğünde TANIMLI olmalı; yoksa
     * jetona verilemeyen bir yetki anlatılıyor demektir.
     */
    public function test_belgedeki_yetkiler_tanimli(): void
    {
        $registry = app(PermissionRegistry::class);

        foreach (app(ApiDocumentation::class)->endpoints() as $endpoint) {
            $this->assertNotSame('', $endpoint['ability'], "{$endpoint['name']} için yetki yazılmamış.");

            [$resource, $action] = explode(':', $endpoint['ability'], 2);

            $this->assertTrue(
                $registry->supports($resource, $action),
                "Tanımsız yetki: {$endpoint['ability']} ({$endpoint['name']})",
            );
        }
    }

    public function test_belge_ekrani_uclari_listeler(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.api.docs'))
            ->assertOk()
            ->assertSee('/api/v1/switches')
            ->assertSee('switches:view')
            ->assertSee('Authorization')
            // Jeton sahibini aşamaz kuralı belgede yazılı olmalı.
            ->assertSee('AŞAMAZ', false);
    }

    public function test_belge_ekrani_yonetici_ister(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.api.docs'))
            ->assertForbidden();
    }
}
