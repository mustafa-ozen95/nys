<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * KENDİ KENDİNE PAROLA SIFIRLAMA VE KAYIT YOKTUR.
 *
 * Bu bir ağ yönetim panelidir: hesabı yönetici açar, parolayı yönetici verir.
 * Sıfırlama akışı e-posta kutusunu hesabın anahtarı hâline getirir — posta
 * kutusuna erişen, switchlere yazma yetkisi olan bir hesabı ele geçirir.
 *
 * Bu testler akışın GERİ GELMEMESİNİ kilitler. Kaldırmak kolay olmalı ama
 * geriye "ayarlıyorum ama çalışmıyor" diyecek ölü bir bağlantı da
 * bırakılmamalı (bkz. .ai/rules/modulerlik.md).
 */
final class KendiKendineSifirlamaYokTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: string}>
     */
    public static function kaldirilanRotalar(): array
    {
        return [
            'sıfırlama isteği' => ['password.request'],
            'sıfırlama e-postası' => ['password.email'],
            'jetonla sıfırlama' => ['password.reset'],
            'yeni parola' => ['password.update'],
            'kayıt ol' => ['register'],
        ];
    }

    #[DataProvider('kaldirilanRotalar')]
    public function test_kaldirilan_rota_tanimli_degil(string $ad): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName($ad),
            "{$ad} rotası geri gelmiş; kendi kendine parola sıfırlama ve kayıt YOKTUR.",
        );
    }

    /**
     * Oturumsuz erişilebilen uçlar SAYILIDIR.
     *
     * Liste bilerek elle tutulur: yeni bir uç eklendiğinde bu test düşer ve
     * "bu gerçekten oturumsuz açılmalı mı?" sorusu bir kez daha sorulur.
     * Otomatik geçen bir kural, sessizce açılan bir kapı demekti.
     *
     * Merkez uçları kayıt akışı DEĞİLDİR: eşleşmeyen kimlik için hesap
     * açılmaz, yalnızca yöneticinin açtığı bir hesaba giriş yapılır (bkz.
     * MerkezGirisTest::test_eslesmeyen_kimlik_icin_hesap_acilmaz).
     */
    public function test_oturumsuz_uclar_sayilidir(): void
    {
        /*
         * Ölçüt "guest taşıyor mu" DEĞİL, "auth taşımıyor mu".
         *
         * İlki yalnızca bilerek açılmış uçları görürdü; ikisini de taşımayan
         * bir uç — yani unutulmuş olan — listeye hiç girmez ve test sessizce
         * geçerdi. Asıl aradığımız tam olarak o uç.
         */
        $acik = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($rota): bool => ! collect($rota->gatherMiddleware())
                ->contains(fn ($ara): bool => is_string($ara) && str_starts_with($ara, 'auth')))
            ->reject(fn ($rota): bool => str_starts_with($rota->uri(), '_'))
            ->map(fn ($rota): string => $rota->uri())
            ->unique()
            ->sort()
            ->values()
            ->all();

        /*
         * Kalan üç uç ve neden oturumsuz oldukları:
         *
         *  - `ajax/uzak-oturum/dogrula`: nginx'in `auth_request` ile sorduğu
         *    bekçi. `auth` altına alınamaz — o katman kimliksiz isteği login'e
         *    YÖNLENDİRİR, nginx ise 2xx/401/403 dışındaki her yanıtı 500 sayar.
         *    Kimlik kontrolü controller içinde yapılır ve 401 döner.
         *
         *  - `giris` ve merkez uçları: giriş akışının kendisi.
         *
         *  - `up`: Laravel'in sağlık ucu; hiçbir veri döndürmez.
         *
         * API uçları burada YOK, çünkü `auth:api` taşıyorlar.
         */
        /*
         * `api/kimlik/tazele` OTURUM TAŞIMAZ ve taşıyamaz.
         *
         * Çağıran bir insan değil, Kimlik Yönetim Merkezi'dir: "şu kimlik
         * değişti" der. Kimlik doğrulaması imzadır (istemciye verilen bildirim
         * sırrıyla HMAC) ve gelen gövdeye zaten güvenilmez — panel doğruyu
         * merkezden kendi sorar. Oturum aransaydı bildirim hiç ulaşamazdı.
         */
        $this->assertSame([
            'ajax/uzak-oturum/dogrula',
            'api/kimlik/cikis',
            'api/kimlik/tazele',
            'giris',
            'giris/merkez',
            'giris/merkez/geri',
            'up',
        ], $acik);
    }

    /**
     * Giriş ekranında "parolamı unuttum" bağlantısı KALMAZ: çalışmayan bir
     * bağlantı, olmayan bir özellikten kötüdür.
     */
    public function test_giris_ekraninda_unuttum_baglantisi_yok(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('unuttum', false)
            ->assertDontSee('parola-sifirla');
    }

    /**
     * Kaldırılan uçlar 404 döner; gizli bir yol kalmadı.
     */
    public function test_eski_adresler_kapali(): void
    {
        foreach (['/parola-sifirla', '/parola-sifirla/jeton', '/parola-yenile', '/kayit'] as $adres) {
            $this->get($adres)->assertNotFound();
        }
    }

    /**
     * Parolayı YÖNETİCİ verir; kimse kilitli kalmaz.
     */
    public function test_yonetici_kullanicinin_parolasini_degistirebilir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $kullanici = User::factory()->create();

        $this->actingAs($yonetici)
            ->put(route('users.update', $kullanici), [
                'name' => $kullanici->name,
                'email' => $kullanici->email,
                'status' => $kullanici->status->value,
                'role' => $kullanici->role?->uuid ?? Role::query()->value('uuid'),
                'password' => 'yeni-parola-123456',
                'password_confirmation' => 'yeni-parola-123456',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(
            auth()->validate(['email' => $kullanici->email, 'password' => 'yeni-parola-123456']),
        );
    }
}
