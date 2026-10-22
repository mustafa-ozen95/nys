<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Settings\LegacyKeys;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Config dosyaları İngilizce adlandırıldı; `settings` tablosundaki satırlar
 * eski adlarıyla (kimlik.*, teklif.*, basit_mod.*) kalır ve yalnızca
 * veritabanı sınırında çevrilir (bkz. LegacyKeys).
 */
class LegacySettingKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_ad_cevirisi_iki_yonlu_ve_kapsam_disina_dokunmaz(): void
    {
        $this->assertSame('identity.acik', LegacyKeys::toCurrent('kimlik.acik'));
        $this->assertSame('kimlik.acik', LegacyKeys::toStored('identity.acik'));
        $this->assertSame('quote.kdv_orani', LegacyKeys::toCurrent('teklif.kdv_orani'));
        $this->assertSame('simple_mode.alanlar.x.acik', LegacyKeys::toCurrent('basit_mod.alanlar.x.acik'));
        $this->assertSame('mail.host', LegacyKeys::toCurrent('mail.host'));
        $this->assertSame('mail.host', LegacyKeys::toStored('mail.host'));
        $this->assertSame('app', LegacyKeys::toCurrent('app'));
    }

    public function test_yazilan_satir_eski_adla_durur_ve_yeni_adla_okunur(): void
    {
        $repository = app(SettingsRepository::class);

        $written = ! (bool) config('identity.acik');
        $repository->save(['identity.acik' => $written]);

        $this->assertSame(['kimlik.acik'], Setting::query()->pluck('key')->all());
        $this->assertSame($written, $repository->get('identity.acik'));
        $this->assertNull($repository->get('kimlik.acik'));
    }

    public function test_eski_adli_satir_yeni_config_anahtarina_uygulanir(): void
    {
        $repository = app(SettingsRepository::class);
        $written = ! (bool) config('identity.acik');
        $repository->save(['identity.acik' => $written]);
        config(['identity.acik' => ! $written]);
        Cache::forget('settings:values');

        $repository->apply();

        $this->assertSame($written, config('identity.acik'));
    }

    public function test_onbellekte_kalmis_eski_adli_dizi_de_ceviriden_gecer(): void
    {
        Cache::forever('settings:values', ['kimlik.acik' => false]);

        $this->assertFalse(app(SettingsRepository::class)->get('identity.acik'));
    }
}
