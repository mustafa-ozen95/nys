<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kullanıcının kendi hesabı.
 *
 * "Kendi profilini düzenle" yetkisi, kendini yönetici yapma yetkisi
 * DEĞİLDİR. Bu testlerin asıl işi o sınırı kilitlemek.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $onto = []): User
    {
        return User::factory()->create([
            'name' => 'Deneme Kişi',
            'email' => 'kisi@ornek.test',
            'password' => Hash::make('parola-1234'),
            ...$onto,
        ]);
    }

    #[Test]
    public function profile_sessionless_not_opened(): void
    {
        $this->get(route('account.profile'))->assertRedirect(route('login'));
    }

    #[Test]
    public function kullanici_kendi_adini_degistirir(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->put(route('account.profile.update'), ['name' => 'Yeni Ad'])
            ->assertSessionHas('status');

        $this->assertSame('Yeni Ad', $user->fresh()->name);
    }

    /**
     * E-posta merkezî girişte hesap eşleştirme anahtarıdır; kullanıcı
     * kendi adresini yazıp başkasının ilk KYM girişini devralamamalı.
     */
    #[Test]
    public function kullanici_kendi_epostasini_degistiremez(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->put(route('account.profile.update'), ['name' => 'Yeni Ad', 'email' => 'yeni@ornek.test'])
            ->assertSessionHas('status');

        $this->assertSame('kisi@ornek.test', $user->fresh()->email);
        $this->assertSame('Yeni Ad', $user->fresh()->name);
    }

    /** Rol formda yok; gönderilse bile yok sayılmalı. */
    #[Test]
    public function profile_role_unchangeable(): void
    {
        $user = $this->user();
        $previous = $user->role_id;

        $this->actingAs($user)->put(route('account.profile.update'), [
            'name' => 'Ad',
            'email' => 'kisi@ornek.test',
            'role_id' => 1,
            'status' => 'active',
        ]);

        $this->assertSame($previous, $user->fresh()->role_id);
    }

    #[Test]
    public function parola_degistirmek_mevcut_parolayi_ister(): void
    {
        $user = $this->user();

        $this->actingAs($user)->put(route('account.password'), [
            'mevcut' => 'yanlis',
            'password' => 'yeni-parola-42',
            'password_confirmation' => 'yeni-parola-42',
        ])->assertSessionHasErrors('mevcut');

        $this->assertTrue(Hash::check('parola-1234', $user->fresh()->password));
    }

    #[Test]
    public function dogru_mevcut_parolayla_degisir(): void
    {
        $user = $this->user();

        $this->actingAs($user)->put(route('account.password'), [
            'mevcut' => 'parola-1234',
            'password' => 'yeni-parola-42',
            'password_confirmation' => 'yeni-parola-42',
        ])->assertSessionHas('status');

        $this->assertTrue(Hash::check('yeni-parola-42', $user->fresh()->password));
    }

    /**
     * Parola başka yerden değişince (kendi ya da yönetici sıfırlaması)
     * eskiden açılmış oturum DÜŞMELİ; yoksa ele geçirilmiş oturum parola
     * değişiminden sonra da yaşar.
     */
    #[Test]
    public function parola_baska_yerden_degisince_eski_oturum_duser(): void
    {
        $user = $this->user();

        $this->post(route('login'), ['email' => 'kisi@ornek.test', 'password' => 'parola-1234']);
        $this->get(route('account.profile'))->assertOk();

        // Başka bir cihazdan ya da yöneticiden gelen parola değişimi.
        $user->forceFill(['password' => Hash::make('baska-parola-9876')])->save();

        // Sınama tek uygulama örneği kullanır; gerçekte her istek kullanıcıyı yeniden okur.
        $this->app['auth']->forgetGuards();

        $this->get(route('account.profile'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** Parolayı değiştiren oturumun kendisi açık kalır. */
    #[Test]
    public function parolayi_degistiren_oturum_acik_kalir(): void
    {
        $this->user();

        $this->post(route('login'), ['email' => 'kisi@ornek.test', 'password' => 'parola-1234']);
        $this->get(route('account.profile'))->assertOk();

        $this->put(route('account.password'), [
            'mevcut' => 'parola-1234',
            'password' => 'yeni-parola-42',
            'password_confirmation' => 'yeni-parola-42',
        ])->assertSessionHas('status');

        $this->get(route('account.profile'))->assertOk();
    }

    #[Test]
    public function kisa_parola_kabul_edilmez(): void
    {
        $user = $this->user();

        $this->actingAs($user)->put(route('account.password'), [
            'mevcut' => 'parola-1234',
            'password' => 'kisa1',
            'password_confirmation' => 'kisa1',
        ])->assertSessionHasErrors('password');
    }

    /** Giriş ekranı, hesabı olmayana ne yapacağını söylemeli. */
    #[Test]
    public function giris_ekrani_hesap_istegini_anlatir(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Hesap açma isteği için sistem yöneticisine başvurun.');
    }
}
