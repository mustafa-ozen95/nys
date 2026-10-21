<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\NetconfSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * CİHAZDAN GELEN XML GÜVENİLMEZ VERİDİR.
 *
 * Cihaz ele geçirilmiş, taklit edilmiş ya da araya girilmiş olabilir; sürüm
 * yükseltmesi beklenmedik bir yapı döndürebilir. Panel bunların hiçbirinde
 * ne sızdırmalı ne de kilitlenmeli.
 *
 * Dış varlıklar yüklenmez (XXE yok); İÇ varlıklar da genişletilmemeli.
 * NETCONF yanıtında doküman türü bildirimi hiç bulunmadığı için böyle bir
 * yanıt tümden reddedilir.
 */
final class HostileResponseTest extends TestCase
{
    public function test_doktipli_yanit_reddedilir(): void
    {
        $this->expectException(NetconfException::class);
        $this->expectExceptionMessage('doküman türü bildirimi');

        $this->oku(
            '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x "AAAA">]>'
            .'<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><data>&x;</data></rpc-reply>'
        );
    }

    /**
     * Yerel dosya okuma denemesi hiçbir şey sızdırmamalı — ve zaten yanıtın
     * tamamı reddedilmeli.
     */
    public function test_yerel_dosya_okuma_denemesi_sizdirmaz(): void
    {
        try {
            $sonuc = $this->oku(
                '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
                .'<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><data>&x;</data></rpc-reply>'
            );

            $this->fail('Doktipli yanıt kabul edildi: '.$sonuc);
        } catch (NetconfException $e) {
            $this->assertStringNotContainsString('root:', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function bozukYanitlar(): array
    {
        return [
            'kesik XML' => ['<rpc-reply><configuration><interfaces><interface><name>ge-0/0/0'],
            'XML değil' => ['BU BIR XML DEGIL'],
            'boş bayt' => ["<rpc-reply><data>x\0y</data></rpc-reply>"],
            'derin iç içe' => ['<rpc-reply>'.str_repeat('<a>', 5000).str_repeat('</a>', 5000).'</rpc-reply>'],
        ];
    }

    #[DataProvider('bozukYanitlar')]
    public function test_bozuk_yanit_temiz_reddedilir(string $xml): void
    {
        $this->expectException(NetconfException::class);

        $this->oku($xml);
    }

    /**
     * Beklenmedik ama GEÇERLİ bir yapı hata değildir: yeni bir Junos sürümü
     * tanımadığımız elemanlar döndürebilir ve panel bunu okumaya devam
     * etmelidir.
     */
    public function test_tanimadigi_yapi_kabul_edilir(): void
    {
        $sonuc = $this->oku(
            '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0">'
            .'<gelecekteki-bilinmeyen-eleman><ic>x</ic></gelecekteki-bilinmeyen-eleman></rpc-reply>'
        );

        $this->assertStringContainsString('gelecekteki-bilinmeyen-eleman', $sonuc);
    }

    private function oku(string $xml): string
    {
        $transport = new FakeNetconfTransport([
            '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability></capabilities></hello>'."\n]]>]]>",
            $xml."\n]]>]]>",
        ]);

        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (string) $session->rpc('<get-software-information/>')->asXML();
    }
}
