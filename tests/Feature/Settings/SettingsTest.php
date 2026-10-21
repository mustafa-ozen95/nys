<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\MeasurementSource;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ayarlar_yalnizca_yoneticiye_acilir(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.index'))
            ->assertForbidden();

        $yonetici = User::factory()->admin()->create();

        // Giriş sayfası durum özetidir; bölümler ayrı sayfalardadır.
        $this->actingAs($yonetici)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Hangi kaynak konuşuyor')
            ->assertSee('Kuyrukta bekleyen')
            ->assertSee('Kullanan switch');

        $this->actingAs($yonetici)
            ->get(route('settings.show', 'cihaz'))
            ->assertOk()
            ->assertSee('Geri alınabilir commit süresi');
    }

    public function test_olmayan_bolum_bulunamadi_doner(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.show', 'boyle-bir-sey-yok'))
            ->assertNotFound();
    }

    public function test_yonetici_olmayan_ayar_kaydedemez(): void
    {
        $this->actingAs(User::factory()->create())
            ->put(route('settings.update', 'cihaz'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_kaydedilen_ayar_config_uzerine_binder(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('settings.update', 'cihaz'), $this->payload(['netconf__confirm_minutes' => 7]))
            ->assertRedirect();

        $this->assertDatabaseHas('settings', ['key' => 'netconf.confirm_minutes']);

        // Bindirme yeni bir istekte config üzerinden görünmeli.
        app(SettingsRepository::class)->forget();
        app(SettingsRepository::class)->apply();

        $this->assertSame(7, config('netconf.confirm_minutes'));
    }

    public function test_degismeyen_ayar_icin_satir_yazilmaz(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('settings.update', 'cihaz'), $this->payload())
            ->assertRedirect();

        // Hepsi mevcut config değerleriyle aynı gönderildi.
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_bos_birakilan_jeton_mevcut_degeri_silmez(): void
    {
        Setting::create(['key' => 'zabbix.api_token', 'value' => Crypt::encryptString(json_encode('gizli-jeton'))]);
        app(SettingsRepository::class)->forget();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('settings.update', 'izleme'), $this->payload(['zabbix__api_token' => '']))
            ->assertRedirect();

        app(SettingsRepository::class)->forget();

        $this->assertSame('gizli-jeton', app(SettingsRepository::class)->get('zabbix.api_token'));
    }

    /**
     * Jeton ekranda hiç basılmaz ve boş bırakılınca korunur; adres
     * değişirse jeton yeni adrese giderdi. Adres değişince jeton yeniden
     * girilmeden kayıt olmaz.
     */
    public function test_adres_degisince_kayitli_jeton_yeniden_istenir(): void
    {
        Setting::create(['key' => 'zabbix.api_token', 'value' => Crypt::encryptString(json_encode('gizli-jeton'))]);
        Setting::create(['key' => 'zabbix.url', 'value' => Crypt::encryptString(json_encode('https://izleme.ornek.test'))]);
        app(SettingsRepository::class)->forget();
        app(SettingsRepository::class)->apply();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('settings.update', 'izleme'), $this->payload([
                'zabbix__url' => 'https://toplayici.saldirgan.test',
                'zabbix__api_token' => '',
            ]))
            ->assertSessionHasErrors('zabbix__api_token');

        app(SettingsRepository::class)->forget();
        $this->assertNotSame('https://toplayici.saldirgan.test', app(SettingsRepository::class)->get('zabbix.url'));
    }

    public function test_adres_degisirken_jeton_yeniden_verilirse_kaydedilir(): void
    {
        Setting::create(['key' => 'zabbix.api_token', 'value' => Crypt::encryptString(json_encode('gizli-jeton'))]);
        app(SettingsRepository::class)->forget();
        app(SettingsRepository::class)->apply();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('settings.update', 'izleme'), $this->payload([
                'zabbix__url' => 'https://yeni-izleme.ornek.test',
                'zabbix__api_token' => 'yeni-jeton',
            ]))
            ->assertSessionHasNoErrors();

        app(SettingsRepository::class)->forget();
        $this->assertSame('https://yeni-izleme.ornek.test', app(SettingsRepository::class)->get('zabbix.url'));
        $this->assertSame('yeni-jeton', app(SettingsRepository::class)->get('zabbix.api_token'));
    }

    public function test_jeton_hicbir_ekranda_basilmaz(): void
    {
        Setting::create(['key' => 'zabbix.api_token', 'value' => Crypt::encryptString(json_encode('cok-gizli-jeton'))]);
        app(SettingsRepository::class)->forget();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.show', 'izleme'))
            ->assertOk()
            ->assertDontSee('cok-gizli-jeton');
    }

    public function test_araligin_disindaki_deger_reddedilir(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('settings.update', 'cihaz'), $this->payload(['netconf__confirm_minutes' => 0]))
            ->assertSessionHasErrors('netconf__confirm_minutes');

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_bilinmeyen_olcum_kaynagi_reddedilir(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('settings.update', 'olcum'), $this->payload(['measurement__source' => 'sihirli-kure']))
            ->assertSessionHasErrors('measurement__source');
    }

    public function test_kaynak_degistirilince_kaydedilir(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('settings.update', 'olcum'), $this->payload(['measurement__source' => MeasurementSource::Netconf->value]))
            ->assertRedirect();

        app(SettingsRepository::class)->forget();

        $this->assertSame(MeasurementSource::Netconf->value, app(SettingsRepository::class)->get('measurement.source'));
    }

    public function test_bir_bolum_kaydedilince_digerleri_bozulmaz(): void
    {
        $yonetici = User::factory()->admin()->create();

        // Önce ölçüm bölümü değiştirilir.
        $this->actingAs($yonetici)
            ->put(route('settings.update', 'olcum'), [
                'measurement__source' => MeasurementSource::Netconf->value,
                'measurement__fallback' => '1',
                'measurement__failure_ttl' => 90,
                'measurement__sample_retention_days' => 3,
            ])
            ->assertSessionHasNoErrors();

        // Sonra bambaşka bir bölüm gönderilir; ölçüm alanları istekte yok.
        $this->actingAs($yonetici)
            ->put(route('settings.update', 'cihaz'), [
                'netconf__port' => 830,
                'netconf__timeout' => 25,
                'netconf__confirm_minutes' => 5,
                'netconf__change_detection' => '1',
            ])
            ->assertSessionHasNoErrors();

        app(SettingsRepository::class)->forget();
        $ayarlar = app(SettingsRepository::class);

        /*
         * Gönderilmeyen ayar "boşaltıldı" sayılsaydı ilk bölüm silinirdi:
         * tek bir kaydetme bütün yapılandırmayı götürürdü.
         */
        $this->assertSame(MeasurementSource::Netconf->value, $ayarlar->get('measurement.source'));
        $this->assertSame(90, $ayarlar->get('measurement.failure_ttl'));
        $this->assertSame(5, $ayarlar->get('netconf.confirm_minutes'));
    }

    public function test_isaretsiz_kutu_kapatildi_sayilir(): void
    {
        // Gizli "0" alanı sayesinde işaretsiz kutu da gönderilir.
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('settings.update', 'izleme'), [
                'zabbix__enabled' => '0',
                'zabbix__url' => config('zabbix.url'),
                'zabbix__timeout' => config('zabbix.timeout'),
                'zabbix__web_timeout' => config('zabbix.web_timeout'),
                'zabbix__retry_times' => config('zabbix.retry_times'),
                'zabbix__cache__host_map' => config('zabbix.cache.host_map'),
                'zabbix__cache__traffic' => config('zabbix.cache.traffic'),
            ])
            ->assertSessionHasNoErrors();

        app(SettingsRepository::class)->forget();

        $this->assertFalse(app(SettingsRepository::class)->get('zabbix.enabled'));
    }

    /**
     * Formun tamamı gönderilir: eksik alan "boşaltıldı" sayılır.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $values = [];

        foreach (config('settings.items') as $key => $definition) {
            $field = str_replace('.', '__', $key);
            $values[$field] = ($definition['type'] ?? 'string') === 'boolean'
                ? (config($key) ? '1' : '0')
                : config($key);
        }

        return [...$values, ...$overrides];
    }
}
