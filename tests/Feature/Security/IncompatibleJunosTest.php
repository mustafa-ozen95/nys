<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\JunosSchemaProfile;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Junos\JunosDialect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * İLERİDE UYUMSUZ BİR JUNOS GELİRSE NE OLUR?
 *
 * Panel bugün iki aileyi tanıyor; yarın QFX, EX9200 ya da tanımadığımız bir
 * sürüm gelebilir. Bu testler panelin o gün NE YAPACAĞINI önceden söyler.
 *
 * Beklenen davranış tek cümlede: BİLMEDİĞİNİ UYDURMAZ, ne bildiğini SÖYLER.
 */
final class IncompatibleJunosTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tanınmayan model için aile UYDURULMAZ.
     *
     * Yanlış aile, cihaza yanlış sözdizimi yazmak demektir. "Herhalde
     * ELS'tir" demek yerine null döner ve kesin cevabı cihaza soran şema
     * sondası verir.
     */
    public function test_taninmayan_model_icin_aile_uydurulmaz(): void
    {
        foreach (['QFX5120-48Y', 'EX9204', 'MX960', 'SRX345', 'BILINMEYEN-9000'] as $model) {
            $this->assertNull(
                JunosSchemaProfile::guess($model, '24.4R1'),
                "{$model} için aile uyduruldu; tanınmayan model tahmin edilmemeli.",
            );
        }

        $this->assertNull(JunosSchemaProfile::guess(null, null));
    }

    /** Tanıdığı modelleri de doğru bilmeli, yoksa "hiçbir şey bilmiyorum" bir erdem olurdu. */
    public function test_tanidigi_modelleri_dogru_bilir(): void
    {
        $this->assertSame(JunosSchemaProfile::PreEls, JunosSchemaProfile::guess('EX2200-48T-4G', '12.3R12.4'));
        $this->assertSame(JunosSchemaProfile::Els, JunosSchemaProfile::guess('EX2300-24T', '24.4R2-S4'));
    }

    /**
     * Tanımsız bir lehçe SESSİZCE geçmez.
     *
     * Veritabanına elle yazılmış ya da göçten kalmış bir anahtar, panelin
     * varsayılana düşüp yanlış sözdizimi yazmasına yol açmamalı.
     */
    public function test_tanimsiz_lehce_sessizce_gecmez(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JunosDialect::make('junos-2030');
    }

    /**
     * Yeni bir aile eklemek TEK sınıfa dokunmak olmalı: ezilmeyen her soru
     * varsayılanını korur.
     */
    public function test_yeni_aile_yalnizca_farkli_olani_yazar(): void
    {
        $future = new class extends JunosDialect
        {
            public function key(): string
            {
                return 'gelecek';
            }

            public function l3InterfaceName(): string
            {
                return 'xrb';
            }
        };

        $this->assertSame('xrb', $future->l3InterfaceName());

        // Ezilmeyenler ELS öncesi varsayılanında kalır.
        $this->assertSame('port-mode', $future->switchingModeElement());
        $this->assertSame('ethernet-switching-options', $future->analyzerRootElement());
        $this->assertFalse($future->usesPlainSpeed());
        $this->assertFalse($future->requiresMacLimitAction());
    }

    /**
     * Şema BİLİNMİYORSA operatör bunu ekranda görmeli.
     *
     * Panel bilinmeyen şemada ELS öncesi varsayar; yanlış ailede cihaz yazmayı
     * reddeder ve hata sebepsiz görünür. Sebebi ekranda yazmalı.
     */
    public function test_bilinmeyen_sema_ekranda_soylenir(): void
    {
        $switch = NetworkSwitch::factory()->create(['schema_profile' => null]);

        $this->actingAs($this->observer())
            ->get(route('switches.show', $switch))
            ->assertOk()
            ->assertSee('Bilinmiyor — ELS öncesi varsayılıyor');
    }

    public function test_bilinen_sema_ekranda_adiyla_gorunur(): void
    {
        $switch = NetworkSwitch::factory()->create(['schema_profile' => JunosSchemaProfile::Els]);

        $this->actingAs($this->observer())
            ->get(route('switches.show', $switch))
            ->assertOk()
            ->assertSee(JunosSchemaProfile::Els->label());
    }

    private function observer(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        return $user->fresh();
    }
}
