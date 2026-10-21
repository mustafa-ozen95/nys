<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Enums\PanelMode;
use App\Models\NetworkSwitch;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Basit modda bölüm bazında görüntüle / yönet.
 *
 * Kural yetki katmanında: basit moddaki kişinin yetkisi "rolünün verdiği ∩
 * basit modda açık olan". Yalnızca daraltır; gelişmiş moddaki yetkili kişi ve
 * yönetici etkilenmez. Üç giriş (allows, allowsAny, scope) aynı kararı verir.
 */
final class BasitModBolumTest extends TestCase
{
    use RefreshDatabase;

    private function kullanici(PanelMode $mod, bool $gelismisYetkisi = false): User
    {
        $user = User::factory()->create(['panel_mode' => $mod]);
        $r = app(UserPermissionResolver::class);
        $r->syncResource($user, 'switches', ['view']);
        $r->syncResource($user, 'switch_vlans', ['view', 'create', 'update', 'delete']);

        if ($gelismisYetkisi) {
            $r->syncResource($user, 'gorunum_modu', ['view']);
        }

        return $user->fresh();
    }

    public function test_varsayilanda_basit_mod_hicbir_seyi_daraltmaz(): void
    {
        $user = $this->kullanici(PanelMode::Simple);
        $r = app(UserPermissionResolver::class);

        $this->assertTrue($r->allowsAny($user, 'switch_vlans', 'view'));
        $this->assertTrue($r->allowsAny($user, 'switch_vlans', 'update'));
    }

    public function test_kapali_bolum_gorulmez_ve_yonetilmez(): void
    {
        config(['basit_mod.bolumler.switch_vlans.goruntule' => false]);
        $user = $this->kullanici(PanelMode::Simple);
        $r = app(UserPermissionResolver::class);
        $switch = NetworkSwitch::factory()->create();

        $this->assertFalse($r->allowsAny($user, 'switch_vlans', 'view'));
        $this->assertFalse($r->allows($user, 'switch_vlans', 'view', $switch->id));
        $this->assertFalse($r->allows($user, 'switch_vlans', 'update', $switch->id), 'Görmediği bölümü yönetemez.');
        $this->assertSame(0, $r->scope(NetworkSwitch::query(), $user, 'switch_vlans')->count(), 'Liste de boş döner.');

        /* Başka bölüme dokunulmaz. */
        $this->assertTrue($r->allowsAny($user, 'switches', 'view'));
    }

    public function test_yalnizca_yonet_kapaliysa_gorulur_ama_degistirilemez(): void
    {
        config(['basit_mod.bolumler.switch_vlans.yonet' => false]);
        $user = $this->kullanici(PanelMode::Simple);
        $r = app(UserPermissionResolver::class);

        $this->assertTrue($r->allowsAny($user, 'switch_vlans', 'view'));
        foreach (['create', 'update', 'delete'] as $eylem) {
            $this->assertFalse($r->allowsAny($user, 'switch_vlans', $eylem), $eylem);
        }
    }

    public function test_gelismis_moddaki_yetkili_ve_yonetici_etkilenmez(): void
    {
        config(['basit_mod.bolumler.switch_vlans.goruntule' => false]);
        $r = app(UserPermissionResolver::class);

        $this->assertTrue($r->allowsAny($this->kullanici(PanelMode::Advanced, gelismisYetkisi: true), 'switch_vlans', 'update'));
        $this->assertTrue($r->allowsAny(User::factory()->admin()->create(['panel_mode' => PanelMode::Simple]), 'switch_vlans', 'view'));

        /* Kaydı "gelişmiş" ama yetkisi yok: basit sayılır ve kısıt uygulanır. */
        $this->assertFalse($r->allowsAny($this->kullanici(PanelMode::Advanced), 'switch_vlans', 'view'));
    }

    public function test_kisit_rol_yetkisini_genisletmez(): void
    {
        $user = User::factory()->create(['panel_mode' => PanelMode::Simple]);

        $this->assertFalse(app(UserPermissionResolver::class)->allowsAny($user, 'switch_vlans', 'view'));
    }

    public function test_ayarlar_ekraninda_basit_mod_bolumu_var(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.show', 'basit-mod'))
            ->assertOk()
            ->assertSee('VLAN’lar')
            ->assertSee('name="basit_mod__bolumler__switch_vlans__goruntule"', false)
            ->assertSee('name="basit_mod__bolumler__switch_vlans__yonet"', false);
    }

    public function test_roller_ekrani_gelismis_mod_yetkisiyle_acilir(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.roles'))
            ->assertOk()
            ->assertSee('Gelişmiş mod');
    }

    public function test_rol_duzenleme_ekrani_gelismis_mod_satirini_cizer(): void
    {
        $rol = Role::query()->create(['slug' => 'deneme', 'label' => 'Deneme', 'is_admin' => false, 'template' => ['gorunum_modu' => ['view']]]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.roles.show', $rol))
            ->assertOk()
            ->assertSee('Gelişmiş mod');
    }

    public function test_goc_network_rolune_gelismis_mod_verir(): void
    {
        $rol = Role::query()->where('slug', 'network')->first()
            ?? Role::query()->create(['slug' => 'network', 'label' => 'Network', 'is_admin' => false, 'template' => ['switches' => ['view']]]);
        $rol->forceFill(['template' => ['switches' => ['view']]])->save();

        (require database_path('migrations/2026_10_02_120000_network_gelismis_moda_gecebilir.php'))->up();

        $sablon = json_decode((string) DB::table('roles')->where('id', $rol->id)->value('template'), true);
        $this->assertSame(['view'], $sablon['gorunum_modu'] ?? null);
        $this->assertSame(['view'], $sablon['switches'] ?? null, 'Var olan yetkilere dokunulmaz.');
    }
}
