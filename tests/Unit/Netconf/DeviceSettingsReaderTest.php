<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Services\Netconf\DeviceSettingsReader;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeNetconfTransport;

/**
 * Cihazın kendi ayarlarının okunması.
 *
 * Yanıt gövdeleri jn32-test'in gerçek çıktısından alınmıştır.
 */
final class DeviceSettingsReaderTest extends TestCase
{
    public function test_giris_hesaplari_cozumlenir(): void
    {
        $ayarlar = $this->read(
            '<system><login>'
            .'<user><name>admin</name><uid>200</uid><class>super-user</class>'
            .'<authentication><encrypted-password>$1$abc$def</encrypted-password></authentication></user>'
            .'<user><name>saha</name><full-name>Saha Ekibi</full-name><uid>2500</uid>'
            .'<class>read-only</class></user>'
            .'</login></system>'
        );

        $this->assertCount(2, $ayarlar['login_users']);

        $this->assertSame('admin', $ayarlar['login_users'][0]['name']);
        $this->assertSame(200, $ayarlar['login_users'][0]['uid']);
        $this->assertSame('super-user', $ayarlar['login_users'][0]['class']);
        $this->assertTrue($ayarlar['login_users'][0]['has_password']);

        $this->assertSame('Saha Ekibi', $ayarlar['login_users'][1]['full_name']);
        $this->assertFalse($ayarlar['login_users'][1]['has_password']);
    }

    /**
     * Parolanın KENDİSİ okunmaz: cihazın tuttuğu özet panelde işe yaramaz
     * ve yalnızca çalınabilecek bir sır daha olurdu.
     */
    public function test_parola_ozeti_okunmaz(): void
    {
        $ayarlar = $this->read(
            '<system><login><user><name>admin</name><class>super-user</class>'
            .'<authentication><encrypted-password>$1$gizli$ozet</encrypted-password></authentication>'
            .'</user></login></system>'
        );

        $this->assertStringNotContainsString('gizli', json_encode($ayarlar) ?: '');
    }

    /**
     * Sınıfı yazılmamış hesap cihazda giriş yapamaz; panel de öyle
     * göstermeli, "bilinmiyor" değil.
     */
    public function test_sinifsiz_hesap_yetkisiz_sayilir(): void
    {
        $ayarlar = $this->read('<system><login><user><name>eski</name></user></login></system>');

        $this->assertSame('unauthorized', $ayarlar['login_users'][0]['class']);
        $this->assertNull($ayarlar['login_users'][0]['uid']);
    }

    public function test_sasi_alarmlari_cozumlenir(): void
    {
        $ayarlar = $this->read(
            '<chassis><alarm>'
            .'<ethernet><link-down>red</link-down></ethernet>'
            .'<management-ethernet><link-down>ignore</link-down></management-ethernet>'
            .'</alarm></chassis>'
        );

        $this->assertSame('red', $ayarlar['alarms']['ethernet']);
        $this->assertSame('ignore', $ayarlar['alarms']['management']);
    }

    public function test_yazilmamis_alarm_null_kalir(): void
    {
        $ayarlar = $this->read('<system><host-name>test1</host-name></system>');

        $this->assertNull($ayarlar['alarms']['ethernet']);
        $this->assertNull($ayarlar['alarms']['management']);
        $this->assertSame([], $ayarlar['login_users']);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $body): array
    {
        $reply = '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="1">'
            .'<configuration>'.$body.'</configuration></rpc-reply>'."\n]]>]]>";

        $transport = new FakeNetconfTransport([$this->hello(), $reply]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (new DeviceSettingsReader)->read($session);
    }

    private function hello(): string
    {
        return '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability>'
            ."</capabilities></hello>\n]]>]]>";
    }
}
