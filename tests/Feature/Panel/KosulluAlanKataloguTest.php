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
 * Görünümdeki <x-form.field kural="…"> ile config/basit_mod.php · alanlar
 * AYNI listeyi söylemeli: biri eklenip öbürü unutulursa ya ayarı olmayan bir
 * alan ya da hiçbir alanı etkilemeyen bir ayar kalır.
 */
final class KosulluAlanKataloguTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        View::share('errors', new ViewErrorBag);
    }

    public function test_gorunumler_ile_katalog_ayni_listeyi_soyler(): void
    {
        $gorunumde = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $dosya) {
            if (str_ends_with($dosya->getFilename(), '.blade.php')) {
                preg_match_all('/<x-form\.field\b[^>]*\bkural="([^"]+)"/s', file_get_contents($dosya->getPathname()), $m);
                $gorunumde = array_merge($gorunumde, $m[1]);
            }
        }

        $katalogda = [];
        foreach (config('basit_mod.alanlar') as $bolum => $alanlar) {
            $this->assertArrayHasKey($bolum, config('basit_mod.bolumler'), "Alanın bölümü yok: {$bolum}");
            foreach (array_keys($alanlar) as $alan) {
                $katalogda[] = "{$bolum}.{$alan}";
            }
        }

        $this->assertSame([], array_values(array_diff(array_unique($gorunumde), $katalogda)), 'Görünümde olup katalogda olmayan.');
        $this->assertSame([], array_values(array_diff($katalogda, $gorunumde)), 'Katalogda olup görünümde olmayan.');
    }

    public function test_her_alanin_ayari_var(): void
    {
        foreach (config('basit_mod.alanlar') as $bolum => $alanlar) {
            foreach (array_keys($alanlar) as $alan) {
                $this->assertArrayHasKey("basit_mod.alanlar.{$bolum}.{$alan}.acik", config('settings.items'));
            }
        }
    }

    private function cizim(PanelMode $mod, ?bool $acik): string
    {
        $user = User::factory()->create(['panel_mode' => $mod]);
        if ($mod === PanelMode::Advanced) {
            app(UserPermissionResolver::class)->syncResource($user, 'gorunum_modu', ['view']);
        }
        $this->actingAs($user->fresh());

        if ($acik !== null) {
            config(['basit_mod.alanlar.switch_vlans.vlan:description.acik' => $acik]);
        }

        return Blade::render('<x-form.field kural="switch_vlans.vlan:description" name="description" label="Açıklama"><input name="description" value="cihazdaki"></x-form.field>');
    }

    public function test_kapali_alan_basit_modda_gizlenir_ama_formda_kalir(): void
    {
        $html = $this->cizim(PanelMode::Simple, false);

        $this->assertStringContainsString('field--basit-gizli', $html);
        $this->assertStringContainsString('name="description" value="cihazdaki"', $html, 'Girdi formda kalmalı: değer korunur.');
    }

    public function test_acik_alan_ve_gelismis_mod_gizlemez(): void
    {
        $this->assertStringNotContainsString('field--basit-gizli', $this->cizim(PanelMode::Simple, null));
        $this->assertStringNotContainsString('field--basit-gizli', $this->cizim(PanelMode::Advanced, false));
    }

    public function test_gelismis_isaretli_alan_ayarla_basit_modda_acilir(): void
    {
        $this->actingAs(User::factory()->create(['panel_mode' => PanelMode::Simple]));
        $ciz = fn () => Blade::render('<x-form.field advanced kural="switch_ports.port:mtu" name="mtu" label="MTU"><input name="mtu"></x-form.field>');

        $this->assertStringNotContainsString('name="mtu"', $ciz(), 'Varsayılan: basit modda çizilmez.');

        config(['basit_mod.alanlar.switch_ports.port:mtu.acik' => true]);
        $this->assertStringContainsString('name="mtu"', $ciz(), 'Ayarla açılınca basit modda da çizilir.');
    }

    public function test_basit_mod_ayar_ekrani_bolum_kartlarini_cizer(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.show', 'basit-mod'))
            ->assertOk()
            ->assertSee('basit-mod__bolum', false)
            ->assertSee('Firewall')
            ->assertSee('basit_mod__alanlar__switch_vlans__vlan:description__acik', false);
    }

    public function test_alan_ayari_kaydedilir(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('settings.update', 'basit-mod'), ['basit_mod__alanlar__switch_vlans__vlan:description__acik' => '0'])
            ->assertRedirect();

        $this->assertFalse(app(SettingsRepository::class)->get('basit_mod.alanlar.switch_vlans.vlan:description.acik'));
    }

    /**
     * Alan anahtarında NOKTA OLMAZ: yapılandırma noktayı iç içe yol sayar,
     * bileşen katalogdaki değeri bulamaz ve kaydedilen ayar katalogun yanında
     * ikinci bir dal açardı.
     */
    public function test_alan_anahtarinda_nokta_yok_ve_ayar_katalogu_bozmaz(): void
    {
        foreach (config('basit_mod.alanlar') as $bolum => $alanlar) {
            foreach (array_keys($alanlar) as $alan) {
                $this->assertStringNotContainsString('.', $alan, "{$bolum}: {$alan}");
            }
        }

        config(['basit_mod.alanlar.switch_firewall.terim:source_ports.acik' => false]);

        $this->assertFalse(config('basit_mod.alanlar.switch_firewall.terim:source_ports.acik'));
        $this->assertSame('Terim · Kaynak portlar', config('basit_mod.alanlar.switch_firewall.terim:source_ports.etiket'));
        foreach (config('basit_mod.alanlar.switch_firewall') as $alan => $tanim) {
            $this->assertArrayHasKey('varsayilan', $tanim, "Katalogda eksik dal: {$alan}");
        }
    }
}
