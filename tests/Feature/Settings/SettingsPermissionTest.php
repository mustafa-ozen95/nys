<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Settings\SettingsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ayar BÖLÜMÜ düzeyinde yetki.
 *
 * Ayarların tamamı tek bir anahtar değildir: ölçüm kaynağını değiştirmek ile
 * geri alınabilir commit süresini değiştirmek aynı ağırlıkta değil.
 *
 * Varsayılan KAPALIDIR: hiçbir rol şablonunda "settings" yoktur.
 */
final class SettingsPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /* Bu sınıf yetki düzeneğini sınıyor; üretimde kapalı (bkz. TestCase). */
        $this->yetkiDagitimiAc();
    }

    /** config/settings.php içindeki kalıcı yetki kimlikleri. */
    private const OLCUM = 1;

    private const BAKIM = 10;

    public function test_varsayilan_olarak_kapalidir(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.index'))
            ->assertForbidden();
    }

    public function test_bolum_yetkisi_verilen_kullanici_o_bolumu_gorur(): void
    {
        $user = $this->withSection('view', [self::OLCUM]);

        $this->actingAs($user)->get(route('settings.show', 'olcum'))->assertOk();
        $this->actingAs($user)->get(route('settings.show', 'bakim'))->assertForbidden();
    }

    public function test_seritte_yalnizca_gorebildigi_bolumler_gorunur(): void
    {
        $user = $this->withSection('view', [self::OLCUM]);

        $tabs = collect(app(SettingsCatalog::class)->tabs('olcum', $user))->pluck('label');

        $this->assertTrue($tabs->contains('Ölçüm kaynağı'));
        $this->assertFalse($tabs->contains('Bakım'));

        // Rol ve parola ekranları yalnızca yöneticide.
        $this->assertFalse($tabs->contains('Roller'));
        $this->assertFalse($tabs->contains('Cihaz parolaları'));
    }

    public function test_gorme_yetkisi_yazma_yetkisi_degildir(): void
    {
        $user = $this->withSection('view', [self::OLCUM]);

        $this->actingAs($user)->get(route('settings.show', 'olcum'))->assertOk();

        $this->actingAs($user)
            ->put(route('settings.update', 'olcum'), ['measurement__failure_ttl' => '120'])
            ->assertForbidden();
    }

    public function test_yazma_yetkisi_verilen_bolum_kaydedilir(): void
    {
        $user = $this->withSection(['view', 'update'], [self::OLCUM]);

        $this->actingAs($user)
            ->put(route('settings.update', 'olcum'), ['measurement__failure_ttl' => '123'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', ['key' => 'measurement.failure_ttl']);
    }

    /**
     * Bir bölüme yetkisi olan biri BAŞKA bölümün alanını o forma iliştirip
     * kaydedemez. Sınır olmasaydı bölüm bazlı yetki hiçbir şey ifade etmezdi.
     */
    public function test_baska_bolumun_alani_forma_ilistirilemez(): void
    {
        $user = $this->withSection(['view', 'update'], [self::OLCUM]);

        $this->actingAs($user)
            ->put(route('settings.update', 'olcum'), [
                'measurement__failure_ttl' => '123',
                'maintenance__activity_retention_days' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('settings', ['key' => 'maintenance.activity_retention_days']);
    }

    public function test_yonetici_butun_bolumleri_gorur(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('settings.index'))->assertOk();
        $this->actingAs($admin)->get(route('settings.show', 'bakim'))->assertOk();

        $tabs = collect(app(SettingsCatalog::class)->tabs(null, $admin))->pluck('label');

        $this->assertTrue($tabs->contains('Roller'));
        $this->assertTrue($tabs->contains('Cihaz parolaları'));
    }

    /**
     * Yetki ekranı ayar bölümlerini kayıt olarak listeler; kayıtlar
     * veritabanından değil config'ten gelir.
     */
    public function test_yetki_ekrani_ayar_bolumlerini_listeler(): void
    {
        $hedef = User::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('users.permissions', ['user' => $hedef, 'resource' => 'settings']))
            ->assertOk()
            ->assertSee('Ölçüm kaynağı')
            ->assertSee('Bakım');
    }

    /**
     * @param  string|list<string>  $actions
     * @param  list<int>  $groupIds
     */
    private function withSection(string|array $actions, array $groupIds): User
    {
        $user = User::factory()->create();

        $allowed = [];

        foreach ((array) $actions as $action) {
            $allowed[$action] = $groupIds;
        }

        app(UserPermissionResolver::class)->syncResource($user, 'settings', [], $allowed);

        return $user->fresh();
    }
}
