<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GUACD'NİN YAZILABİLİR BİR HOME'U OLMALI.
 *
 * FreeRDP, sunucu sertifikalarını `$HOME/.config/freerdp` altında tutar ve bu
 * dizini AÇAMAZSA bağlantıyı TLS/NLA pazarlığı hiç başlamadan keser. guacd
 * root değil "nsm" olarak koşuyor; supervisor'a `environment` verilmezse
 * süreç root'un HOME'unu miras alır, `/root/.config`e yazamaz ve hata
 * ekrana şöyle yansır:
 *
 *     RDP server closed/refused connection: Security negotiation failed
 *     (wrong security type?)
 *
 * Mesaj güvenlik kipini işaret ettiği için parola ya da NLA sorunu
 * sanılabilir. Aynı makineye aynı kimlikle doğrudan `xfreerdp` (root olarak)
 * BAŞARILI olur; tek fark HOME'dur. `HOME=/var/lib/guacamole` verildiğinde
 * guacd hem `any` hem `nla` kipinde bağlanır.
 *
 * Ayrıca RDP kanal eklentileri (guac-common-svc, guacsnd, guacdr) guac'ın
 * prefix'ine değil FreeRDP'nin kendi dizinine kurulur; imaja o dizin de
 * kopyalanmazsa aynı hata mesajıyla düşer.
 */
final class GuacdEnvironmentTest extends TestCase
{
    #[Test]
    public function guacd_yazilabilir_bir_home_ile_baslatilir(): void
    {
        $betik = file_get_contents(base_path('docker/rootfs/usr/local/bin/nsm-entrypoint'));

        $this->assertMatchesRegularExpression(
            '/\[program:guacd\](?:(?!\[program:).)*environment\s*=\s*[^\n]*HOME="[^"]+"/s',
            $betik,
            'guacd supervisor bloğunda HOME tanımlı değil: FreeRDP sertifika deposunu açamaz.'
        );

        $this->assertStringContainsString(
            'chown -R nsm:nsm /var/lib/guacamole',
            $betik,
            'HOME dizini nsm kullanıcısına verilmemiş: dizin açılamaz.'
        );
    }

    #[Test]
    public function rdp_kanal_eklentileri_imaja_kopyalanir(): void
    {
        $this->assertStringContainsString(
            'COPY --from=guacd-derleme /usr/lib/x86_64-linux-gnu/freerdp2',
            file_get_contents(base_path('docker/Dockerfile')),
            'FreeRDP eklenti dizini imaja kopyalanmıyor: rdpdr/rdpsnd kanalları yüklenemez.'
        );
    }
}
