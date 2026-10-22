<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Enums\PanelMode;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Basit modda alan düzeyi.
 *
 * Görünümdeki <x-form.field rule="…"> ile config/simple_mode.php · alanlar
 * AYNI listeyi söylemeli: biri eklenip öbürü unutulursa ya ayarı olmayan bir
 * alan ya da hiçbir alanı etkilemeyen bir ayar kalır.
 */
final class ConditionalFieldCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        View::share('errors', new ViewErrorBag);
    }

    public function test_gorunumler_ile_katalog_ayni_listeyi_soyler(): void
    {
        $view = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                preg_match_all('/<x-form\.field\b[^>]*\brule="([^"]+)"/s', file_get_contents($file->getPathname()), $m);
                $view = array_merge($view, $m[1]);
            }
        }

        $catalog = [];
        foreach (config('simple_mode.alanlar') as $section => $fields) {
            $this->assertArrayHasKey($section, config('simple_mode.bolumler'), "Alanın bölümü yok: {$section}");
            foreach (array_keys($fields) as $alan) {
                $catalog[] = "{$section}.{$alan}";
            }
        }

        $this->assertSame([], array_values(array_diff(array_unique($view), $catalog)), 'Görünümde olup katalogda olmayan.');
        $this->assertSame([], array_values(array_diff($catalog, $view)), 'Katalogda olup görünümde olmayan.');
    }

    public function test_her_alanin_ayari_var(): void
    {
        foreach (config('simple_mode.alanlar') as $section => $fields) {
            foreach (array_keys($fields) as $alan) {
                $this->assertArrayHasKey("simple_mode.alanlar.{$section}.{$alan}.acik", config('settings.items'));
            }
        }
    }

    private function drawing(PanelMode $mode, ?bool $open): string
    {
        $user = User::factory()->create(['panel_mode' => $mode]);
        if ($mode === PanelMode::Advanced) {
            app(UserPermissionResolver::class)->syncResource($user, 'gorunum_modu', ['view']);
        }
        $this->actingAs($user->fresh());

        if ($open !== null) {
            config(['simple_mode.alanlar.switch_vlans.vlan:description.acik' => $open]);
        }

        return Blade::render('<x-form.field rule="switch_vlans.vlan:description" name="description" label="Açıklama"><input name="description" value="cihazdaki"></x-form.field>');
    }

    public function test_kapali_alan_basit_modda_gizlenir_ama_formda_kalir(): void
    {
        $html = $this->drawing(PanelMode::Simple, false);

        $this->assertStringContainsString('field--simple-hidden', $html);
        $this->assertStringContainsString('name="description" value="cihazdaki"', $html, 'Girdi formda kalmalı: değer korunur.');
    }

    public function test_acik_alan_ve_gelismis_mod_gizlemez(): void
    {
        $this->assertStringNotContainsString('field--simple-hidden', $this->drawing(PanelMode::Simple, null));
        $this->assertStringNotContainsString('field--simple-hidden', $this->drawing(PanelMode::Advanced, false));
    }

    public function test_gelismis_isaretli_alan_ayarla_basit_modda_acilir(): void
    {
        $this->actingAs(User::factory()->create(['panel_mode' => PanelMode::Simple]));
        $draw = fn () => Blade::render('<x-form.field advanced rule="switch_ports.port:mtu" name="mtu" label="MTU"><input name="mtu"></x-form.field>');

        $this->assertStringNotContainsString('name="mtu"', $draw(), 'Varsayılan: basit modda çizilmez.');

        config(['simple_mode.alanlar.switch_ports.port:mtu.acik' => true]);
        $this->assertStringContainsString('name="mtu"', $draw(), 'Ayarla açılınca basit modda da çizilir.');
    }

    public function test_simple_mode_ayar_ekrani_bolum_kartlarini_cizer(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.show', 'simple-mode'))
            ->assertOk()
            ->assertSee('simple-mode__section', false)
            ->assertSee('Firewall')
            ->assertSee('simple_mode__alanlar__switch_vlans__vlan:description__acik', false);
    }

    public function test_alan_ayari_kaydedilir(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('settings.update', 'simple-mode'), ['simple_mode__alanlar__switch_vlans__vlan:description__acik' => '0'])
            ->assertRedirect();

        $this->assertFalse(app(SettingsRepository::class)->get('simple_mode.alanlar.switch_vlans.vlan:description.acik'));
    }

    /**
     * Alan anahtarında NOKTA OLMAZ: yapılandırma noktayı iç içe yol sayar,
     * bileşen katalogdaki değeri bulamaz ve kaydedilen ayar katalogun yanında
     * ikinci bir dal açardı.
     */
    public function test_alan_anahtarinda_nokta_yok_ve_ayar_katalogu_bozmaz(): void
    {
        foreach (config('simple_mode.alanlar') as $section => $fields) {
            foreach (array_keys($fields) as $alan) {
                $this->assertStringNotContainsString('.', $alan, "{$section}: {$alan}");
            }
        }

        config(['simple_mode.alanlar.switch_firewall.terim:source_ports.acik' => false]);

        $this->assertFalse(config('simple_mode.alanlar.switch_firewall.terim:source_ports.acik'));
        $this->assertSame('Terim · Kaynak portlar', config('simple_mode.alanlar.switch_firewall.terim:source_ports.etiket'));
        foreach (config('simple_mode.alanlar.switch_firewall') as $alan => $definition) {
            $this->assertArrayHasKey('varsayilan', $definition, "Katalogda eksik dal: {$alan}");
        }
    }
}
