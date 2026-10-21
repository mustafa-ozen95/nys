<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Netconf\ConfigurationRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Cihaz farkı etkinlik kaydına yazılır. Kayıt silinemez ve dışa
 * aktarılabilir; sır oraya HİÇ girmemeli.
 *
 * Junos sırları "$9$..." biçiminde tutar. Bu biçim çözülebilir olduğu için
 * düz metin kadar hassastır ve maskelenmeden bırakılamaz.
 */
final class ConfigurationRedactorTest extends TestCase
{
    public function test_oturum_anahtari_maskelenir(): void
    {
        $fark = ConfigurationRedactor::apply(
            '[edit protocols bgp group transit]'."\n".'+   authentication-key "$9$gizliDeger"; ## SECRET-DATA'
        );

        $this->assertStringNotContainsString('gizliDeger', $fark);
        $this->assertStringContainsString('authentication-key', $fark);
        $this->assertStringContainsString(ConfigurationRedactor::MASK, $fark);
    }

    public function test_tirnaksiz_deger_de_maskelenir(): void
    {
        $fark = ConfigurationRedactor::apply('+   snmp community gizliCommunity;');

        $this->assertStringNotContainsString('gizliCommunity', $fark);
    }

    /**
     * Anahtar kelime tanınmasa bile Junos'un şifreli değeri maskelenir:
     * liste eksik kalırsa sır kayda düşmesin.
     */
    public function test_junos_sifreli_degeri_her_yerde_maskelenir(): void
    {
        $fark = ConfigurationRedactor::apply('+   bilinmeyen-alan "$9$baskaBirSir";');

        $this->assertStringNotContainsString('baskaBirSir', $fark);
    }

    /**
     * Junos community'yi BLOK olarak basar; değer ";" ile değil "{" ile
     * devam eder. Yalnızca ";" beklendiğinde bu biçim kaçıyordu ve ad
     * etkinlik kaydına düz yazılıyordu.
     */
    public function test_blok_acan_deger_de_maskelenir(): void
    {
        $fark = ConfigurationRedactor::apply(
            '[edit snmp] + community "gizlicommunity" { authorization read-only; }'
        );

        $this->assertStringNotContainsString('gizlicommunity', $fark);
        $this->assertStringContainsString('community', $fark);
    }

    /**
     * Maskeleme metnin geri kalanını BOZMAZ: hangi satırın değiştiği
     * görünmeye devam etmeli, yoksa kayıt işe yaramaz.
     */
    public function test_sir_tasimayan_satirlar_korunur(): void
    {
        $fark = "[edit protocols bgp group transit]\n+   hold-time 30;\n+   passive;";

        $this->assertSame($fark, ConfigurationRedactor::apply($fark));
    }
}
