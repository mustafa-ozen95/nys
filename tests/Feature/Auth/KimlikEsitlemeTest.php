<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Merkezdeki değişikliğin buraya taşınması.
 *
 * NEDEN VAR: eşitleme olmasa merkezde rolü değişen ya da hesabı askıya
 * alınan kişi, oturumu açık olduğu için burada erişmeye devam eder. Girişte
 * bakmak yetmez; değişiklik, kişi hiç giriş yapmadan da olur.
 *
 * Testlerin kilitlediği asıl şey, KAPATMANIN SINIRI: merkez yanıt vermediğinde
 * kimse atılmaz, yanıtta anılmayan kimliğe dokunulmaz ve yöneticilik
 * kendiliğinden devrolmaz.
 */
class KimlikEsitlemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kimlik.acik' => true,
            'kimlik.yapilandirildi' => true,
            'kimlik.adres' => 'https://merkez.test',
            'kimlik.istemci' => 'istemci-1',
            'kimlik.sir' => 'sir-1',
            'kimlik.dogrulama_dakika' => 5,
        ]);

        Cache::flush();
    }

    private function bagli(array $ustune = []): User
    {
        $kullanici = User::factory()->create($ustune);

        UserIdentity::create([
            'user_id' => $kullanici->id,
            'provider' => 'kimlik',
            'subject' => 'merkez-'.$kullanici->id,
        ]);

        return $kullanici;
    }

    private function merkezSoyler(User $kullanici, array $durum): void
    {
        Http::fake([
            'merkez.test/api/kimlik-durumu' => Http::response([
                'kimlikler' => ['merkez-'.$kullanici->id => $durum],
            ]),
        ]);
    }

    public function test_merkezde_askiya_alinan_hesap_kapatilir(): void
    {
        $kullanici = $this->bagli();
        $this->merkezSoyler($kullanici, ['durum' => 'askida', 'girebilir' => false, 'rol' => 'network']);

        $this->artisan('ag:kimlik-esitle')->assertSuccessful();

        $this->assertSame(UserStatus::Inactive, $kullanici->fresh()->status);
    }

    /** Merkezde silinen kimlik de erişimini kaybeder; hesap SİLİNMEZ, kapatılır. */
    public function test_merkezde_silinen_kimlik_kapatilir(): void
    {
        $kullanici = $this->bagli();
        $this->merkezSoyler($kullanici, ['durum' => 'yok', 'rol' => null]);

        $this->artisan('ag:kimlik-esitle')->assertSuccessful();

        $taze = $kullanici->fresh();

        $this->assertNotNull($taze, 'Hesap silinmemeli: denetim kaydı sahipsiz kalmamalı.');
        $this->assertSame(UserStatus::Inactive, $taze->status);
    }

    /**
     * Rol değişince YETKİLER DE onunla gelir.
     *
     * Yalnızca adı değiştirip yetkileri eski rolde bırakmak "rol verdim ama
     * hiçbir şey göremiyor" durumunu üretiyordu; ekrandan rol değiştirmekle
     * aynı sonuç olmalı.
     */
    public function test_rol_degisince_yeni_rolun_yetkileri_gecerli_olur(): void
    {
        $teknik = Role::query()->updateOrCreate(['slug' => 'teknik'], ['label' => 'Teknik']);
        $network = Role::query()->updateOrCreate(['slug' => 'network'], [
            'label' => 'Network',
            'template' => ['servers' => ['view', 'connect']],
        ]);

        $kullanici = $this->bagli(['role_id' => $teknik->id]);

        $this->merkezSoyler($kullanici, ['durum' => 'etkin', 'girebilir' => true, 'rol' => 'network']);

        $this->artisan('ag:kimlik-esitle')->assertSuccessful();

        $taze = $kullanici->fresh();

        $this->assertSame($network->id, $taze->role_id);
        $this->assertSame(0, $taze->permissions()->count(), 'Yetki kopyalanmamalı.');
        $this->assertTrue(
            app(UserPermissionResolver::class)->allows($taze, 'servers', 'view'),
            'Yeni rolün yetkisi geçerli olmalı.',
        );
    }

    /** Merkezdeki yöneticilik kendiliğinden devrolmaz. */
    public function test_yoneticilik_devrolmaz(): void
    {
        Role::query()->updateOrCreate(['slug' => 'admin'], ['label' => 'Yönetici', 'is_admin' => true]);
        $teknik = Role::query()->updateOrCreate(['slug' => 'teknik'], ['label' => 'Teknik']);

        $kullanici = $this->bagli(['role_id' => $teknik->id]);

        $this->merkezSoyler($kullanici, ['durum' => 'etkin', 'girebilir' => true, 'rol' => 'admin']);

        $this->artisan('ag:kimlik-esitle')->assertSuccessful();

        $this->assertSame($teknik->id, $kullanici->fresh()->role_id);
        $this->assertFalse($kullanici->fresh()->isAdmin());
    }

    /**
     * YANIT GELMEYEN KİMLİĞE DOKUNULMAZ.
     *
     * "Bilmiyorum"u "yok" saymak, merkezdeki bir aksaklıkta bütün kadroyu
     * panelden atmak olurdu.
     */
    public function test_yanitta_anilmayan_kimlige_dokunulmaz(): void
    {
        $kullanici = $this->bagli();

        Http::fake(['merkez.test/api/kimlik-durumu' => Http::response(['kimlikler' => []])]);

        $this->artisan('ag:kimlik-esitle')->assertSuccessful();

        $this->assertTrue($kullanici->fresh()->isActive());
    }

    /**
     * ERİŞİMİ KALKAN KİŞİ İSTEĞİN ORTASINDA DIŞARI ALINIR.
     *
     * Bir sonraki girişi beklemek, kapatılmış bir hesabın oturumunu açık
     * bırakmak olurdu.
     */
    public function test_erisimi_kalkan_kisi_oturumdan_atilir(): void
    {
        $kullanici = $this->bagli();
        $this->merkezSoyler($kullanici, ['durum' => 'askida', 'girebilir' => false]);

        $this->actingAs($kullanici)
            ->get(route('hesap.profil'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(UserStatus::Inactive, $kullanici->fresh()->status);
    }

    /** Merkez erişilemezse KİMSE ATILMAZ: panelin kendi girişi merkezden bağımsızdır. */
    public function test_merkez_ulasilamazsa_kimse_atilmaz(): void
    {
        $kullanici = $this->bagli();

        Http::fake(['merkez.test/*' => Http::response('', 500)]);

        $this->actingAs($kullanici)->get(route('hesap.profil'))->assertOk();

        $this->assertTrue($kullanici->fresh()->isActive());
        $this->assertAuthenticatedAs($kullanici);
    }

    /** Merkeze HER İSTEKTE sorulmaz; yanıt kullanıcı başına önbelleklenir. */
    public function test_merkeze_her_istekte_sorulmaz(): void
    {
        $kullanici = $this->bagli();
        $this->merkezSoyler($kullanici, ['durum' => 'etkin', 'girebilir' => true, 'rol' => null]);

        $this->actingAs($kullanici)->get(route('hesap.profil'))->assertOk();
        $this->actingAs($kullanici)->get(route('hesap.profil'))->assertOk();

        Http::assertSentCount(1);
    }
}
