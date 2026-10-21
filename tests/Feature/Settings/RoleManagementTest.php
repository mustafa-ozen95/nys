<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rollerin ve hazır yetkilerinin yönetimi.
 *
 * Rol artık bir kayıttır: panelden açılır, şablonu panelden düzenlenir.
 * En kritik davranış, rolü değiştirmenin mevcut kullanıcıların yetkisini
 * KENDİLİĞİNDEN değiştirmemesidir — değiştirseydi bir role dokunmak, o
 * roldeki herkesin elle ayarlanmış istisnalarını sessizce silerdi.
 */
final class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Yerleşik roller merkezdekilerle BİREBİR aynı adları taşır.
     *
     * Karşılığı olmayan rol varsayılana düşer; merkezden gelen kullanıcı
     * böylece başka bir rolün yetkisini almış olur.
     */
    public function test_yerlesik_roller_gocle_gelir(): void
    {
        $this->assertSame(
            ['admin', 'network', 'teknik', 'muhasebe'],
            Role::query()->ordered()->pluck('slug')->all(),
        );

        $this->assertTrue(Role::query()->firstWhere('slug', 'admin')->is_admin);
        $this->assertSame('teknik', Role::default()->slug);
    }

    /**
     * Eski config şablonları kayba uğramadan role taşınmalı.
     */
    public function test_hazir_yetkiler_role_tasinir(): void
    {
        $network = Role::query()->firstWhere('slug', 'network');

        $this->assertContains('configure', $network->abilities()['switches'] ?? []);
        $this->assertSame(['view', 'update'], $network->abilities()['switch_ports'] ?? []);
    }

    public function test_yeni_rol_olusturulur(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.roles.store'), [
                'label' => 'Saha Ekibi',
                'slug' => 'saha',
                'badge' => 'cyan',
                'is_admin' => '0',
                'template' => ['switches' => ['view'], 'switch_ports' => ['view', 'update']],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $role = Role::query()->firstWhere('slug', 'saha');

        $this->assertNotNull($role);
        $this->assertFalse($role->is_system);
        $this->assertSame(['switches' => ['view'], 'switch_ports' => ['view', 'update']], $role->abilities());
    }

    /**
     * Tanımsız kaynak/aksiyon şablona GİRMEZ: hiçbir işe yaramayan yetki
     * satırları üretir ve yetki ekranını yanıltırdı.
     */
    public function test_tanimsiz_kaynak_ve_aksiyon_atilir(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.roles.store'), [
                'label' => 'Deneme',
                'slug' => 'deneme',
                'badge' => 'gray',
                'template' => [
                    'switches' => ['view', 'uydurma'],
                    'olmayan_kaynak' => ['view'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(
            ['switches' => ['view']],
            Role::query()->firstWhere('slug', 'deneme')->abilities(),
        );
    }

    /**
     * ROLÜ DEĞİŞTİRMEK O ROLDEKİ HERKES İÇİN ANINDA GEÇERLİDİR.
     *
     * Rol canlı okunuyor: kimseye kopyalanmadığı için "şablonu uygula" diye
     * bir adım yok. Önceden rolü düzenlemek kimseyi etkilemiyordu.
     */
    public function test_rol_degisikligi_roldekilere_hemen_yansir(): void
    {
        $user = User::factory()->role('teknik')->create();
        $role = Role::query()->firstWhere('slug', 'teknik');
        $role->update(['template' => ['switches' => ['view', 'connect']]]);

        $cozumleyici = app(UserPermissionResolver::class);

        $this->assertTrue($cozumleyici->allows($user->fresh(), 'switches', 'connect'));

        $this->actingAs($this->admin())
            ->put(route('settings.roles.update', $role), [
                'label' => 'Teknik Ekip',
                'badge' => 'gray',
                'template' => ['switches' => ['view']],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($cozumleyici->allows($user->fresh(), 'switches', 'view'));
        $this->assertFalse(
            $cozumleyici->allows($user->fresh(), 'switches', 'connect'),
            'Rolden çıkarılan yetki o roldeki kullanıcıda da kalkmalı.',
        );
    }

    /** Kaç kişiyi etkilediği söylenir; sessizce yapılmaz. */
    public function test_kac_kisiyi_etkiledigi_bildirilir(): void
    {
        User::factory()->role('teknik')->count(2)->create();

        $role = Role::query()->firstWhere('slug', 'teknik');

        $this->actingAs($this->admin())
            ->put(route('settings.roles.update', $role), [
                'label' => 'Teknik',
                'badge' => 'gray',
                'template' => ['switches' => ['view']],
            ])
            ->assertSessionHas('status', fn (string $mesaj): bool => str_contains($mesaj, '2 kullanıcı'));
    }

    /**
     * Rolün yeni hâli ANINDA geçerli: uygulanacak bir şablon yok.
     *
     * Kişiye özel istisna varsa o kazanır — istisna bu kişiye verilmişti.
     */
    public function test_rolun_yeni_hali_hemen_gecerli_olur(): void
    {
        $role = Role::query()->firstWhere('slug', 'teknik');
        $role->update(['template' => ['switches' => ['view']]]);

        $user = User::factory()->role('teknik')->create();

        $cozumleyici = app(UserPermissionResolver::class);

        $this->assertTrue($cozumleyici->allows($user->fresh(), 'switches', 'view'));
        $this->assertFalse($cozumleyici->allows($user->fresh(), 'switches', 'connect'));

        /* Kişisel istisna rolün üstüne biner. */
        $cozumleyici->syncResource($user, 'switches', ['connect']);

        $this->assertTrue($cozumleyici->allows($user->fresh(), 'switches', 'connect'));
        $this->assertTrue($cozumleyici->allows($user->fresh(), 'switches', 'view'), 'Rol yerinde kalmalı.');
    }

    /**
     * Son yönetici rolünün yetkisi alınamaz: alınırsa paneli kimse
     * yönetemez ve geri almak için de yönetici gerekir.
     */
    public function test_son_yonetici_rolu_dusurulemez(): void
    {
        $role = Role::query()->firstWhere('slug', 'admin');

        $this->actingAs($this->admin())
            ->put(route('settings.roles.update', $role), [
                'label' => 'Yönetici',
                'badge' => 'purple',
                'is_admin' => '0',
            ])
            ->assertSessionHasErrors('is_admin');

        $this->assertTrue($role->fresh()->is_admin);
    }

    public function test_ikinci_yonetici_rolu_varken_dusurulebilir(): void
    {
        Role::factory()->admin()->create(['slug' => 'baskomutan', 'label' => 'Baş Yönetici']);
        $role = Role::query()->firstWhere('slug', 'admin');

        $this->actingAs($this->admin())
            ->put(route('settings.roles.update', $role), [
                'label' => 'Yönetici',
                'badge' => 'purple',
                'is_admin' => '0',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($role->fresh()->is_admin);
    }

    /**
     * "Kullanıcı ekle" yetkisi, yönetici üretme yetkisi değildir.
     */
    public function test_yonetici_rolu_varsayilan_olamaz(): void
    {
        $role = Role::query()->firstWhere('slug', 'admin');

        $this->actingAs($this->admin())
            ->put(route('settings.roles.update', $role), [
                'label' => 'Yönetici',
                'badge' => 'purple',
                'is_admin' => '1',
                'is_default' => '1',
            ])
            ->assertSessionHasErrors('is_default');
    }

    public function test_varsayilan_rol_tektir(): void
    {
        $yeni = Role::factory()->create(['slug' => 'saha', 'label' => 'Saha']);

        $this->actingAs($this->admin())
            ->put(route('settings.roles.update', $yeni), [
                'label' => 'Saha',
                'badge' => 'gray',
                'is_default' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Role::query()->where('is_default', true)->count());
        $this->assertSame('saha', Role::default()->slug);
    }

    public function test_yerlesik_rol_silinemez(): void
    {
        $role = Role::query()->firstWhere('slug', 'teknik');

        $this->actingAs($this->admin())
            ->delete(route('settings.roles.destroy', $role))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertModelExists($role);
    }

    public function test_kullanicisi_olan_rol_silinemez(): void
    {
        $role = Role::factory()->create(['slug' => 'saha', 'label' => 'Saha']);
        User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($this->admin())
            ->delete(route('settings.roles.destroy', $role))
            ->assertSessionHas('error');

        $this->assertModelExists($role);
    }

    public function test_bos_ozel_rol_silinir(): void
    {
        $role = Role::factory()->create(['slug' => 'saha', 'label' => 'Saha']);

        $this->actingAs($this->admin())
            ->delete(route('settings.roles.destroy', $role))
            ->assertRedirect(route('settings.roles'));

        $this->assertModelMissing($role);
    }

    public function test_yerlesik_rolun_kod_adi_degistirilemez(): void
    {
        $role = Role::query()->firstWhere('slug', 'teknik');

        $this->actingAs($this->admin())
            ->put(route('settings.roles.update', $role), [
                'label' => 'Teknik Ekip',
                'slug' => 'baska',
                'badge' => 'gray',
            ])
            ->assertSessionHasErrors('slug');

        $this->assertSame('teknik', $role->fresh()->slug);
    }

    /**
     * Rol yetkisi verebilen biri kendine her şeyi verebilirdi.
     */
    public function test_yonetici_olmayan_giremez(): void
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'users', ['view', 'create', 'update']);
        $user = $user->fresh();

        $this->actingAs($user)->get(route('settings.roles'))->assertForbidden();
        $this->actingAs($user)->post(route('settings.roles.store'), [])->assertForbidden();
        $this->actingAs($user)
            ->put(route('settings.roles.update', Role::query()->firstWhere('slug', 'teknik')), [])
            ->assertForbidden();
    }

    public function test_ekran_rolleri_listeler(): void
    {
        $this->actingAs($this->admin())
            ->get(route('settings.roles'))
            ->assertOk()
            ->assertSee('Roller')
            ->assertSee('Ağ Ekibi')
            ->assertSee('varsayılan');
    }

    public function test_rol_ekrani_sablonu_gosterir(): void
    {
        $role = Role::query()->firstWhere('slug', 'network');

        $this->actingAs($this->admin())
            ->get(route('settings.roles.show', $role))
            ->assertOk()
            ->assertSee('Hazır yetkiler')
            ->assertSee('Portlar');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * KAYITLI YETKİLER EKRANDA İŞARETLİ GELİR.
     *
     * Gelmediğinde kullanıcı "kaydet" deyince gördüğü şey — yani hiçbir
     * yetki — yazılıyor ve kayıtlı yetkiler sessizce siliniyor; düzeltmek
     * için ikinci kez işaretlemek gerekiyordu.
     */
    public function test_kayitli_yetkiler_ekranda_isaretli_gelir(): void
    {
        $role = Role::query()->firstWhere('slug', 'teknik');
        $role->update(['template' => ['switches' => ['view', 'connect']]]);

        $govde = $this->actingAs($this->admin())
            ->get(route('settings.roles.show', $role))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="template\[switches\]\[\]"[^>]*value="connect"[^>]*checked/s',
            $govde,
        );
    }
}
