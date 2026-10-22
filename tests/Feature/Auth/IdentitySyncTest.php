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
class IdentitySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'identity.acik' => true,
            'identity.configured' => true,
            'identity.url' => 'https://merkez.test',
            'identity.client_id' => 'istemci-1',
            'identity.client_secret' => 'sir-1',
            'identity.verify_minutes' => 5,
        ]);

        Cache::flush();
    }

    private function bound(array $onto = []): User
    {
        $user = User::factory()->create($onto);

        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'kimlik',
            'subject' => 'merkez-'.$user->id,
        ]);

        return $user;
    }

    private function centerSays(User $user, array $status): void
    {
        Http::fake([
            'merkez.test/api/identity-status' => Http::response([
                'identities' => ['merkez-'.$user->id => $status],
            ]),
        ]);
    }

    public function test_merkezde_askiya_alinan_hesap_kapatilir(): void
    {
        $user = $this->bound();
        $this->centerSays($user, ['status' => 'askida', 'can_enter' => false, 'role' => 'network']);

        $this->artisan('identity:sync')->assertSuccessful();

        $this->assertSame(UserStatus::Inactive, $user->fresh()->status);
    }

    /** Merkezde silinen kimlik de erişimini kaybeder; hesap SİLİNMEZ, kapatılır. */
    public function test_merkezde_silinen_kimlik_kapatilir(): void
    {
        $user = $this->bound();
        $this->centerSays($user, ['status' => 'yok', 'role' => null]);

        $this->artisan('identity:sync')->assertSuccessful();

        $fresh = $user->fresh();

        $this->assertNotNull($fresh, 'Hesap silinmemeli: denetim kaydı sahipsiz kalmamalı.');
        $this->assertSame(UserStatus::Inactive, $fresh->status);
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
        $technical = Role::query()->updateOrCreate(['slug' => 'teknik'], ['label' => 'Teknik']);
        $network = Role::query()->updateOrCreate(['slug' => 'network'], [
            'label' => 'Network',
            'template' => ['servers' => ['view', 'connect']],
        ]);

        $user = $this->bound(['role_id' => $technical->id]);

        $this->centerSays($user, ['status' => 'etkin', 'can_enter' => true, 'role' => 'network']);

        $this->artisan('identity:sync')->assertSuccessful();

        $fresh = $user->fresh();

        $this->assertSame($network->id, $fresh->role_id);
        $this->assertSame(0, $fresh->permissions()->count(), 'Yetki kopyalanmamalı.');
        $this->assertTrue(
            app(UserPermissionResolver::class)->allows($fresh, 'servers', 'view'),
            'Yeni rolün yetkisi geçerli olmalı.',
        );
    }

    /** Merkezdeki yöneticilik kendiliğinden devrolmaz. */
    public function test_yoneticilik_devrolmaz(): void
    {
        Role::query()->updateOrCreate(['slug' => 'admin'], ['label' => 'Yönetici', 'is_admin' => true]);
        $technical = Role::query()->updateOrCreate(['slug' => 'teknik'], ['label' => 'Teknik']);

        $user = $this->bound(['role_id' => $technical->id]);

        $this->centerSays($user, ['status' => 'etkin', 'can_enter' => true, 'role' => 'admin']);

        $this->artisan('identity:sync')->assertSuccessful();

        $this->assertSame($technical->id, $user->fresh()->role_id);
        $this->assertFalse($user->fresh()->isAdmin());
    }

    /**
     * YANIT GELMEYEN KİMLİĞE DOKUNULMAZ.
     *
     * "Bilmiyorum"u "yok" saymak, merkezdeki bir aksaklıkta bütün kadroyu
     * panelden atmak olurdu.
     */
    public function test_yanitta_anilmayan_kimlige_dokunulmaz(): void
    {
        $user = $this->bound();

        Http::fake(['merkez.test/api/identity-status' => Http::response(['identities' => []])]);

        $this->artisan('identity:sync')->assertSuccessful();

        $this->assertTrue($user->fresh()->isActive());
    }

    /**
     * ERİŞİMİ KALKAN KİŞİ İSTEĞİN ORTASINDA DIŞARI ALINIR.
     *
     * Bir sonraki girişi beklemek, kapatılmış bir hesabın oturumunu açık
     * bırakmak olurdu.
     */
    public function test_erisimi_kalkan_kisi_oturumdan_atilir(): void
    {
        $user = $this->bound();
        $this->centerSays($user, ['status' => 'askida', 'can_enter' => false]);

        $this->actingAs($user)
            ->get(route('account.profile'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(UserStatus::Inactive, $user->fresh()->status);
    }

    /** Merkez erişilemezse KİMSE ATILMAZ: panelin kendi girişi merkezden bağımsızdır. */
    public function test_merkez_ulasilamazsa_kimse_atilmaz(): void
    {
        $user = $this->bound();

        Http::fake(['merkez.test/*' => Http::response('', 500)]);

        $this->actingAs($user)->get(route('account.profile'))->assertOk();

        $this->assertTrue($user->fresh()->isActive());
        $this->assertAuthenticatedAs($user);
    }

    /** Merkeze HER İSTEKTE sorulmaz; yanıt kullanıcı başına önbelleklenir. */
    public function test_merkeze_her_istekte_sorulmaz(): void
    {
        $user = $this->bound();
        $this->centerSays($user, ['status' => 'etkin', 'can_enter' => true, 'role' => null]);

        $this->actingAs($user)->get(route('account.profile'))->assertOk();
        $this->actingAs($user)->get(route('account.profile'))->assertOk();

        Http::assertSentCount(1);
    }
}
