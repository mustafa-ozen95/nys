<?php

declare(strict_types=1);

namespace Tests\Unit\Junos;

use App\Enums\JunosSchemaProfile;
use App\Services\Junos\ElsDialect;
use App\Services\Junos\JunosDialect;
use App\Services\Junos\PreElsDialect;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Junos aileleri arasındaki ŞEKİL farkları servislere serpiştirilmez;
 * lehçelerde durur. Bu test yapının modüler kaldığını kilitler: yeni bir
 * aile eklemek tek bir sınıf + tek bir config satırı olmalı.
 */
final class JunosDialectTest extends TestCase
{
    public function test_her_profil_bir_lehce_bulur(): void
    {
        foreach (JunosSchemaProfile::cases() as $profile) {
            $this->assertSame(
                $profile->value,
                $profile->dialect()->key(),
                "'{$profile->value}' profilinin lehçesi başka bir anahtar bildiriyor.",
            );
        }
    }

    /**
     * Şekil sorularının tamamı lehçeye devredilmeli. Bir soru enum'da
     * kalırsa yeni aile eklemek yine enum'a dokunmak olurdu.
     */
    public function test_sekil_sorulari_lehceden_gelir(): void
    {
        $onceki = JunosSchemaProfile::PreEls;
        $els = JunosSchemaProfile::Els;

        $this->assertSame('port-mode', $onceki->switchingModeElement());
        $this->assertSame('interface-mode', $els->switchingModeElement());

        $this->assertSame('vlan', $onceki->l3InterfaceName());
        $this->assertSame('irb', $els->l3InterfaceName());

        $this->assertSame('ethernet-switching-options', $onceki->analyzerRootElement());
        $this->assertSame('forwarding-options', $els->analyzerRootElement());

        $this->assertFalse($onceki->usesPlainSpeed());
        $this->assertTrue($els->usesPlainSpeed());

        $this->assertTrue($onceki->usesSecureAccessPort());
        $this->assertFalse($els->usesSecureAccessPort());

        $this->assertTrue($onceki->dialect()->supportsAllKeyword());
        $this->assertFalse($els->dialect()->supportsAllKeyword());
    }

    /**
     * Varsayılanlar ELS ÖNCESİ davranıştır: yeni bir lehçe yalnızca
     * AYRILDIĞI yeri yazsın diye.
     */
    public function test_varsayilanlar_els_oncesidir(): void
    {
        $lehce = new class extends JunosDialect
        {
            public function key(): string
            {
                return 'deneme';
            }
        };

        $this->assertSame('port-mode', $lehce->switchingModeElement());
        $this->assertSame('vlan', $lehce->l3InterfaceName());
        $this->assertFalse($lehce->usesPlainSpeed());
        $this->assertTrue($lehce->usesSecureAccessPort());
        $this->assertTrue($lehce->supportsAllKeyword());
        $this->assertSame([], $lehce->vlanScopedSecureAccessFields());
    }

    public function test_model_adindan_aile_tahmin_edilir(): void
    {
        $this->assertSame(JunosSchemaProfile::PreEls, JunosSchemaProfile::guess('EX2200-48T-4G', '12.3R12.4'));
        $this->assertSame(JunosSchemaProfile::Els, JunosSchemaProfile::guess('EX2300-24T', '24.4R2-S4'));

        // Tanınmayan model UYDURULMAZ.
        $this->assertNull(JunosSchemaProfile::guess('QFX5100-48S', '18.4R3'));
        $this->assertNull(JunosSchemaProfile::guess(null, null));
    }

    public function test_tanimsiz_lehce_sessizce_gecmez(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JunosDialect::make('olmayan-aile');
    }

    public function test_lehceler_paylasilir(): void
    {
        $this->assertInstanceOf(PreElsDialect::class, JunosDialect::make('pre_els'));
        $this->assertInstanceOf(ElsDialect::class, JunosDialect::make('els'));
        $this->assertSame(JunosDialect::make('els'), JunosDialect::make('els'));
    }
}
