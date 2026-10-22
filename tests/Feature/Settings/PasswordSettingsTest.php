<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\Settings\SettingsCatalog;
use App\Support\Credentials\PasswordGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Üretilen cihaz parolasının biçimi ayarlardan seçilir ve KALICIDIR.
 *
 * Seçim veritabanına yazılır ve config'in üstüne biner; sonraki her parola
 * turu o ayarla üretir. "Son yapıldığı şekilde" kalması budur.
 *
 * Cihazın kuralı doğrulamada da uygulanır: yalnız küçük (ya da yalnız büyük)
 * harften oluşan bir birleşim kaydedilemez, çünkü öyle bir parola commit
 * check'te düşer ve tur ortada kalırdı.
 */
final class PasswordSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Biçim ayarı AYRI bir sekme değil: parolaların gösterildiği ekranın
     * içinde durur. Biçimi seçtiğin yer ile sonucunu gördüğün yer aynı olmalı.
     */
    public function test_bicim_parolalar_ekraninda_duzenlenir(): void
    {
        $this->actingAs($this->admin())
            ->get(route('settings.passwords'))
            ->assertOk()
            ->assertSee('Üretilecek parolanın biçimi')
            ->assertSee('Parola uzunluğu')
            ->assertSee('Küçük harf')
            ->assertSee('Büyük harf')
            ->assertSee('Rakam')
            ->assertSee('Noktalama')
            ->assertSee('Karışan karakterleri ele');
    }

    /**
     * Şeritte ayrı bir "Cihaz parolası" sekmesi YOK; tek sayfa.
     */
    public function test_seritte_ayri_sekme_yok(): void
    {
        $tabs = collect(app(SettingsCatalog::class)->tabs(null, $this->admin()))
            ->pluck('label');

        $this->assertFalse($tabs->contains('Cihaz parolası'));
        $this->assertTrue($tabs->contains('Cihaz parolaları'));
    }

    /**
     * Seçim kalıcıdır: kaydedilen değer config'in üstüne biner ve sonraki
     * turlar onu kullanır.
     */
    public function test_secim_kaydedilir_ve_uretimde_kullanilir(): void
    {
        $this->actingAs($this->admin())
            ->put(route('settings.update', 'device-password'), $this->payload([
                'credentials__rotation__length' => '32',
                'credentials__rotation__symbols' => '1',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', ['key' => 'credentials.rotation.length']);
        $this->assertSame(32, (int) config('credentials.rotation.length'));

        $password = (new PasswordGenerator)->generate();

        $this->assertSame(32, mb_strlen($password));
        $this->assertMatchesRegularExpression('/[!#%+,\-.:=?@^_~]/', $password);
    }

    /**
     * Kapatılan sınıf da kaydedilmeli: işaretsiz kutu "gönderilmedi" sayılıp
     * sessizce eski değerinde kalırsa kullanıcı kapattığını sanır.
     */
    public function test_kapatilan_sinif_kaydedilir(): void
    {
        $this->actingAs($this->admin())
            ->put(route('settings.update', 'device-password'), $this->payload([
                'credentials__rotation__uppercase' => '0',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) config('credentials.rotation.uppercase'));

        $all = '';

        for ($i = 0; $i < 20; $i++) {
            $all .= (new PasswordGenerator)->generate();
        }

        $this->assertDoesNotMatchRegularExpression('/[A-Z]/', $all);
    }

    /**
     * Cihaz TEK karakter sınıfından oluşan parolayı reddediyor (yalnız
     * rakam ve yalnız noktalama dahil); ayar da reddetmeli.
     */
    public function test_tek_sinifli_birlesim_kaydedilemez(): void
    {
        foreach ([
            ['uppercase' => '0', 'digits' => '0', 'symbols' => '0'],
            ['lowercase' => '0', 'uppercase' => '0', 'symbols' => '0'],
        ] as $selection) {
            $submission = [];

            foreach ($selection as $name => $value) {
                $submission["credentials__rotation__{$name}"] = $value;
            }

            $this->actingAs($this->admin())
                ->put(route('settings.update', 'device-password'), $this->payload($submission))
                ->assertSessionHasErrors();
        }

        $this->assertTrue((bool) config('credentials.rotation.uppercase'));
    }

    /**
     * ENTROPİ TABANI. Cihaz iki sınıfla sekiz karakteri kabul eder ama
     * böyle bir parola özet olarak yedeklere çıkıyor ve çevrimdışı
     * kırılabiliyor; panel bir taban koyar.
     */
    public function test_zayif_kalan_birlesim_kaydedilemez(): void
    {
        $this->actingAs($this->admin())
            ->put(route('settings.update', 'device-password'), $this->payload([
                'credentials__rotation__length' => '8',
                'credentials__rotation__uppercase' => '0',
            ]))
            ->assertSessionHasErrors();

        $this->assertSame(24, (int) config('credentials.rotation.length'));
    }

    /**
     * KISMİ GÖNDERİM kontrolü atlatamaz.
     *
     * Kontrol isteği değil, kaydettikten sonra YÜRÜRLÜKTE OLACAK durumu
     * sınar. Önceki hâli tek alanlık bir PUT ile atlanabiliyordu ve cihazın
     * reddedeceği ayar kalıcı olarak yazılabiliyordu.
     */
    public function test_kismi_gonderim_kontrolu_atlatamaz(): void
    {
        $admin = $this->admin();

        // Önce geçerli bir ara durum: büyük harf kapalı, rakam açık.
        $this->actingAs($admin)
            ->put(route('settings.update', 'device-password'), $this->payload([
                'credentials__rotation__uppercase' => '0',
                'credentials__rotation__length' => '16',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) config('credentials.rotation.uppercase'));

        // Sonra YALNIZCA rakamı kapatan tek alanlık bir istek.
        $this->actingAs($admin)
            ->put(route('settings.update', 'device-password'), [
                'credentials__rotation__digits' => '0',
            ])
            ->assertSessionHasErrors();

        // Ayar yazılmadı: yürürlükteki birleşim yalnız küçük harf olurdu.
        $this->assertTrue((bool) config('credentials.rotation.digits'));
    }

    public function test_hicbir_sinif_acik_birakilamaz(): void
    {
        $this->actingAs($this->admin())
            ->put(route('settings.update', 'device-password'), $this->payload([
                'credentials__rotation__lowercase' => '0',
                'credentials__rotation__uppercase' => '0',
                'credentials__rotation__digits' => '0',
                'credentials__rotation__symbols' => '0',
            ]))
            // Hata gönderilen ilk alana iliştirilir; hangisi olduğu değişebilir.
            ->assertSessionHasErrors();
    }

    public function test_cok_kisa_uzunluk_reddedilir(): void
    {
        $this->actingAs($this->admin())
            ->put(route('settings.update', 'device-password'), $this->payload([
                'credentials__rotation__length' => '4',
            ]))
            ->assertSessionHasErrors('credentials__rotation__length');
    }

    /**
     * Parola ekranı biçimi SABİT metinle anlatmaz; ayar değişince açıklama
     * da değişir.
     */
    public function test_parola_ekrani_secilen_bicimi_anlatir(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('settings.passwords'))
            ->assertOk()
            ->assertSee('küçük harf, büyük harf ve rakam');

        $this->actingAs($admin)
            ->put(route('settings.update', 'device-password'), $this->payload([
                'credentials__rotation__length' => '16',
                'credentials__rotation__symbols' => '1',
            ]))
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('settings.passwords'))
            ->assertOk()
            ->assertSee('16 karakterlik')
            ->assertSee('noktalama');
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'credentials__rotation__length' => '24',
            'credentials__rotation__lowercase' => '1',
            'credentials__rotation__uppercase' => '1',
            'credentials__rotation__digits' => '1',
            'credentials__rotation__symbols' => '0',
            'credentials__rotation__avoid_ambiguous' => '1',
        ], $overrides);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }
}
