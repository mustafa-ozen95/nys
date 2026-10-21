<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Enums\IpFamily;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\PrefixListReader;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeNetconfTransport;

final class PrefixListReaderTest extends TestCase
{
    /**
     * jn32-test'in gerçek çıktısı: "koru" listesi tanımlı ama BOŞ. Bu gerçek
     * bir bulgudur — uplink çıkış filtresi bu listeye dayandığı için bütün
     * trafiği düşürür. Boş liste kaydedilmeli ki ekranda görünsün.
     */
    public function test_bos_prefix_list_kaydedilir(): void
    {
        $lists = $this->read($this->fixture('policy-options-empty'));

        $this->assertCount(1, $lists);
        $this->assertSame('koru', $lists[0]['name']);
        $this->assertSame([], $lists[0]['entries']);
    }

    public function test_girdileri_olan_liste_cozumlenir(): void
    {
        $lists = $this->read($this->reply(<<<'XML'
            <policy-options>
            <prefix-list>
            <name>musteri</name>
            <prefix-list-item><name>198.18.1.0/24</name></prefix-list-item>
            <prefix-list-item><name>10.0.0.0/8</name></prefix-list-item>
            </prefix-list>
            </policy-options>
            XML));

        $this->assertCount(1, $lists);
        $this->assertCount(2, $lists[0]['entries']);
        $this->assertSame('198.18.1.0', $lists[0]['entries'][0]['address']);
        $this->assertSame(24, $lists[0]['entries'][0]['prefix_length']);
        $this->assertSame(IpFamily::Inet, $lists[0]['entries'][0]['family']);
    }

    public function test_maskesiz_girdi_tam_uzunluk_sayilir(): void
    {
        $lists = $this->read($this->reply(<<<'XML'
            <policy-options>
            <prefix-list><name>tekil</name>
            <prefix-list-item><name>198.18.20.195</name></prefix-list-item>
            </prefix-list>
            </policy-options>
            XML));

        $this->assertSame(32, $lists[0]['entries'][0]['prefix_length']);
    }

    public function test_ipv6_girdisi_ayirt_edilir(): void
    {
        $lists = $this->read($this->reply(<<<'XML'
            <policy-options>
            <prefix-list><name>v6</name>
            <prefix-list-item><name>2001:db8::/32</name></prefix-list-item>
            </prefix-list>
            </policy-options>
            XML));

        $this->assertSame(IpFamily::Inet6, $lists[0]['entries'][0]['family']);
        $this->assertSame('2001:db8::', $lists[0]['entries'][0]['address']);
    }

    public function test_policy_options_yoksa_bos_doner(): void
    {
        $this->assertSame([], $this->read($this->reply('<configuration/>')));
    }

    /**
     * Junos bir listeyi "apply-path" ile DİNAMİK doldurabiliyor; test1'de
     * commit check ile geçerli olduğu ölçüldü.
     *
     * İfadenin kendisi MODELLENİR. Yalnız statik girdiler okunsaydı böyle
     * bir liste SIFIR GİRDİLİ görünürdü — oysa bu kod tabanı boş prefix
     * list'i tehlike sayıyor ("uplink çıkışındaki filtre buna dayandığı için
     * hiçbir trafik dışarı çıkamaz"). Kullanıcı olmayan bir tehlikeyi
     * düzeltmeye çalışırdı.
     */
    public function test_apply_path_okunur(): void
    {
        $lists = $this->read($this->reply(
            '<policy-options><prefix-list><name>radius</name>'
            .'<apply-path>system radius-server &lt;*&gt;</apply-path>'
            .'</prefix-list></policy-options>'
        ));

        $this->assertSame(['system radius-server <*>'], $lists[0]['apply_paths']);
        $this->assertSame([], $lists[0]['entries']);
        $this->assertSame([], $lists[0]['residual_xml'], 'Modellenen alan artığa düşmemeli.');
    }

    /**
     * Aynı listede birden çok apply-path olabilir. Yalnızca ilki okunsaydı
     * düzenlemede diğerleri sessizce kaybolurdu.
     */
    public function test_birden_cok_apply_path_okunur(): void
    {
        $lists = $this->read($this->reply(
            '<policy-options><prefix-list><name>yerel</name>'
            .'<apply-path>system radius-server &lt;*&gt;</apply-path>'
            .'<apply-path>interfaces &lt;*&gt; unit &lt;*&gt; family inet address &lt;*&gt;</apply-path>'
            .'</prefix-list></policy-options>'
        ));

        $this->assertSame([
            'system radius-server <*>',
            'interfaces <*> unit <*> family inet address <*>',
        ], $lists[0]['apply_paths']);
    }

    public function test_apply_path_ile_statik_girdi_bir_arada_okunur(): void
    {
        $lists = $this->read($this->reply(
            '<policy-options><prefix-list><name>karma</name>'
            .'<apply-path>system radius-server &lt;*&gt;</apply-path>'
            .'<prefix-list-item><name>10.0.0.0/8</name></prefix-list-item>'
            .'</prefix-list></policy-options>'
        ));

        $this->assertSame(['system radius-server <*>'], $lists[0]['apply_paths']);
        $this->assertCount(1, $lists[0]['entries']);
    }

    public function test_statik_listede_apply_path_bos_kalir(): void
    {
        $lists = $this->read($this->reply(
            '<policy-options><prefix-list><name>kabul</name>'
            .'<prefix-list-item><name>10.0.0.0/8</name></prefix-list-item>'
            .'</prefix-list></policy-options>'
        ));

        $this->assertSame([], $lists[0]['apply_paths']);
        $this->assertSame([], $lists[0]['residual_xml']);
    }

    /**
     * EN ÖNEMLİSİ: panelin TANIMADIĞI satır kaybolmaz, ham hâliyle saklanır.
     *
     * Liste "salt okunur" işaretlenmez; satır korunur ve kullanıcı onun ne
     * olduğunu görebilir.
     */
    public function test_taninmayan_satir_ham_haliyle_saklanir(): void
    {
        $lists = $this->read($this->reply(
            '<policy-options><prefix-list><name>karma</name>'
            .'<apply-groups>ortak</apply-groups>'
            .'<prefix-list-item><name>10.0.0.0/8</name></prefix-list-item>'
            .'</prefix-list></policy-options>'
        ));

        $this->assertSame(['<apply-groups>ortak</apply-groups>'], $lists[0]['residual_xml']);
        $this->assertCount(1, $lists[0]['entries'], 'Tanınan girdiler yine okunmalı.');
    }

    /**
     * @return list<array{name: string, apply_paths: list<string>, residual_xml: list<string>, entries: list<array{address: string, prefix_length: int, family: IpFamily}>}>
     */
    private function read(string $reply): array
    {
        $transport = new FakeNetconfTransport([$this->hello(), $reply]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return (new PrefixListReader)->prefixLists($session);
    }

    private function reply(string $body): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="1">'
            .'<configuration>'.$body.'</configuration></rpc-reply>'."\n]]>]]>";
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__."/../../Fixtures/netconf/{$name}.xml")."\n]]>]]>";
    }

    private function hello(): string
    {
        return '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability></capabilities></hello>'."\n]]>]]>";
    }
}
