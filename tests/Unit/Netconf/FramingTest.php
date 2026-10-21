<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Services\Netconf\Framing\ChunkedFraming;
use App\Services\Netconf\Framing\EndOfMessageFraming;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeNetconfTransport;

final class FramingTest extends TestCase
{
    public function test_base_1_0_mesaji_ayrac_ile_cerceveler(): void
    {
        $framing = new EndOfMessageFraming;

        $this->assertSame("<rpc/>\n]]>]]>\n", $framing->encode('<rpc/>'));
        $this->assertSame(
            '<rpc-reply/>',
            $framing->read(new FakeNetconfTransport(["<rpc-reply/>\n]]>]]>\n"])),
        );
        $this->assertSame('<rpc-reply/>', $framing->decode("<rpc-reply/>\n]]>]]>\n"));
    }

    public function test_base_1_1_mesaji_uzunluk_onekiyle_cerceveler(): void
    {
        $framing = new ChunkedFraming;

        $this->assertSame("\n#6\n<rpc/>\n##\n", $framing->encode('<rpc/>'));

        $transport = new FakeNetconfTransport(["\n#12\n<rpc-reply/>\n##\n"]);

        $this->assertSame('<rpc-reply/>', $framing->read($transport));
    }

    /**
     * SINIRI UZUNLUK BELİRLER, ayırıcı değil.
     *
     * Parça verisinin İÇİNDE "\n##\n" geçebilir ve bu uydurma bir durum
     * değildir: Junos yapılandırma metni "## Last changed: ..." satırıyla
     * başlar, panel de kullanıcının yapıştırdığı config metnini gönderip aynı
     * metni "compare rollback 0" farkında geri okur. Ayırıcıyı akışta arayan
     * okuyucu mesajı tam orta yerinden keser.
     */
    public function test_base_1_1_parca_verisi_ayraci_icerse_de_mesaj_bolunmez(): void
    {
        $payload = "<rpc-reply><configuration-output>\n## Last changed: 2020-01-01\n"
            ."##\nset system host-name test</configuration-output></rpc-reply>";

        $transport = new FakeNetconfTransport(["\n#".strlen($payload)."\n".$payload."\n##\n"]);

        $this->assertSame($payload, (new ChunkedFraming)->read($transport));
    }

    /**
     * Çok parçalı mesaj: parçalar birleştirilir, sınır her parçada uzunluktan
     * okunur.
     */
    public function test_base_1_1_akistan_cok_parcali_mesaj_okur(): void
    {
        $transport = new FakeNetconfTransport(["\n#5\n<rpc-\n#7\nreply/>\n##\n"]);

        $this->assertSame('<rpc-reply/>', (new ChunkedFraming)->read($transport));
    }

    public function test_base_1_1_bozuk_baslik_akista_da_hata_verir(): void
    {
        $this->expectException(NetconfException::class);

        (new ChunkedFraming)->read(new FakeNetconfTransport(["\n#abc\n<rpc-reply/>\n##\n"]));
    }

    public function test_base_1_1_cok_parcali_mesaji_birlestirir(): void
    {
        $framing = new ChunkedFraming;

        $raw = "\n#5\n<rpc-\n#7\nreply/>\n##\n";

        $this->assertSame('<rpc-reply/>', $framing->decode($raw));
    }

    public function test_base_1_1_bozuk_uzunluk_hatasi_verir(): void
    {
        $this->expectException(NetconfException::class);

        (new ChunkedFraming)->decode("\n#abc\n<rpc-reply/>\n##\n");
    }

    /**
     * jn32-test (EX4200, 12.3R12.4) hello yetenekleri: yalnızca 1.0.
     */
    public function test_cihaz_yalnizca_1_0_bildirirse_1_0_secilir(): void
    {
        $framing = (new FramingNegotiator)->negotiate(
            [
                'urn:ietf:params:netconf:base:1.0',
                'urn:ietf:params:netconf:capability:candidate:1.0',
                'http://xml.juniper.net/netconf/junos/1.0',
            ],
            ['urn:ietf:params:netconf:base:1.0', 'urn:ietf:params:netconf:base:1.1'],
        );

        $this->assertInstanceOf(EndOfMessageFraming::class, $framing);
    }

    public function test_cihaz_1_1_bildirirse_kendiliginden_1_1_secilir(): void
    {
        $framing = (new FramingNegotiator)->negotiate(
            ['urn:ietf:params:netconf:base:1.0', 'urn:ietf:params:netconf:base:1.1'],
            ['urn:ietf:params:netconf:base:1.0', 'urn:ietf:params:netconf:base:1.1'],
        );

        $this->assertInstanceOf(ChunkedFraming::class, $framing);
    }

    public function test_istemci_1_1_desteklemiyorsa_1_0_kalir(): void
    {
        $framing = (new FramingNegotiator)->negotiate(
            ['urn:ietf:params:netconf:base:1.0', 'urn:ietf:params:netconf:base:1.1'],
            ['urn:ietf:params:netconf:base:1.0'],
        );

        $this->assertInstanceOf(EndOfMessageFraming::class, $framing);
    }

    public function test_cihaz_hicbir_sey_bildirmezse_en_eski_surume_duser(): void
    {
        $framing = (new FramingNegotiator)->negotiate([], ['urn:ietf:params:netconf:base:1.1']);

        $this->assertInstanceOf(EndOfMessageFraming::class, $framing);
    }
}
