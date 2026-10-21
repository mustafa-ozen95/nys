<?php

declare(strict_types=1);

namespace Tests\Unit\Netconf;

use App\Enums\PolicyAction;
use App\Enums\PrefixMatchType;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\PolicyStatementReader;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeNetconfTransport;

/**
 * Politikanın TAMAMI okunur ve modellenir.
 *
 * Omurga tipi bir politika örneğiyle başlar (policy-options-omurga.xml):
 * BGP grubunun import ettiği "prefixes-from-musteri1" zinciri. Sonrası,
 * gelişmiş yapıların (as-path, BGP nitelikleri, varsayılan eylem)
 * modellendiğini ve modellenmeyenlerin ham hâliyle korunduğunu kanıtlar.
 */
final class PolicyStatementReaderTest extends TestCase
{
    public function test_omurga_politikasi_cozumlenir(): void
    {
        $statements = $this->read($this->fixture('policy-options-omurga'));

        $this->assertCount(1, $statements);
        $this->assertSame('prefixes-from-musteri1', $statements[0]['name']);
        $this->assertTrue($statements[0]['is_manageable']);
        $this->assertCount(3, $statements[0]['terms']);
    }

    /**
     * Terim SIRASI anlamlıdır: "her şeyi reddet" en sonda durmalı.
     */
    public function test_terim_sirasi_korunur(): void
    {
        $terms = $this->read($this->fixture('policy-options-omurga'))[0]['terms'];

        $this->assertSame(['clear', 'A', 'B'], array_column($terms, 'name'));
        $this->assertSame([0, 1, 2], array_column($terms, 'position'));
    }

    /**
     * Junos bu alanı ALT ÇİZGİYLE yazar: <list_name>. Şemanın geri kalanı
     * tire kullandığı için kolayca gözden kaçar.
     */
    public function test_prefix_list_filtresi_ve_orlonger_okunur(): void
    {
        $terms = $this->read($this->fixture('policy-options-omurga'))[0]['terms'];

        $this->assertSame(['musteri1-PREFIXES'], $terms[1]['match_prefix_lists']);
        $this->assertSame(PrefixMatchType::OrLonger, $terms[1]['match_type']);
        $this->assertSame(PolicyAction::Accept, $terms[1]['action']);
    }

    /**
     * Junos üç eşleşme türü kabul eder ve biri ZORUNLUDUR; "exact" ile
     * "longer" boolean bir alanla ayırt edilemezdi.
     */
    public function test_diger_eslesme_turleri_ayirt_edilir(): void
    {
        foreach ([PrefixMatchType::Exact, PrefixMatchType::Longer] as $type) {
            $terms = $this->read($this->reply(
                '<policy-options><policy-statement><name>p</name>'
                .'<term><name>t</name><from><prefix-list-filter>'
                .'<list_name>L</list_name><'.$type->value.'/>'
                .'</prefix-list-filter></from><then><accept/></then></term>'
                .'</policy-statement></policy-options>'
            ))[0]['terms'];

            $this->assertSame($type, $terms[0]['match_type']);
        }
    }

    public function test_community_silme_okunur(): void
    {
        $terms = $this->read($this->fixture('policy-options-omurga'))[0]['terms'];

        $this->assertSame('topluluk-a', $terms[0]['community_delete']);
        $this->assertNull($terms[0]['action']);
        $this->assertSame(
            [['operation' => 'delete', 'name' => 'topluluk-a']],
            $terms[0]['then_actions']['communities'],
        );
    }

    public function test_kosulsuz_terim_her_seye_uyar(): void
    {
        $terms = $this->read($this->fixture('policy-options-omurga'))[0]['terms'];

        $this->assertSame([], $terms[2]['match_prefix_lists']);
        $this->assertSame(PolicyAction::Reject, $terms[2]['action']);
    }

    /**
     * EN TEHLİKELİ EKSİK: terimlerden SONRA gelen "then reject;" politikanın
     * VARSAYILAN EYLEMİDİR.
     *
     *   term A { from { prefix-list-filter LISTE orlonger; } then accept; }
     *   then reject;
     *
     * BGP süzgeçlerinin standart biçimi budur ve cihazda geçerli olduğu
     * ölçüldü (test1, commit check). Satır modellenir: okunmasaydı
     * güncelleme politikayı ad+terimler olarak yeniden yazar, satır sessizce
     * kaybolur ve politika "gerisini reddet"ten "gerisini de al"a dönerdi.
     */
    public function test_politika_seviyesindeki_varsayilan_eylem_okunur(): void
    {
        $statement = $this->read($this->reply(
            '<policy-options><policy-statement><name>gelen</name>'
            .'<term><name>a</name><then><accept/></then></term>'
            .'<then><reject/></then>'
            .'</policy-statement></policy-options>'
        ))[0];

        $this->assertTrue($statement['is_manageable'], 'Artık salt okunur yapılmıyor.');
        $this->assertSame('reject', $statement['default_then']['action']);
        $this->assertSame([], $statement['residual_xml']);
    }

    /**
     * Terimsiz politika biçimi: koşul da eylem de politikanın kendi
     * altındadır. Cihazda ölçüldü (test1, commit check).
     */
    public function test_politika_seviyesindeki_kosul_okunur(): void
    {
        $statement = $this->read($this->reply(
            '<policy-options><policy-statement><name>dogrudan</name>'
            .'<from><protocol>direct</protocol></from><then><accept/></then>'
            .'</policy-statement></policy-options>'
        ))[0];

        $this->assertSame([], $statement['terms']);
        $this->assertSame(['direct'], $statement['default_match']['protocols']);
        $this->assertSame('accept', $statement['default_then']['action']);
    }

    /**
     * As-path koşulu politikayı salt okunur yapmaz; modellenir ve
     * düzenlenebilir.
     */
    public function test_as_path_ve_protokol_kosullari_okunur(): void
    {
        $term = $this->read($this->reply(
            '<policy-options><policy-statement><name>p</name>'
            .'<term><name>t</name><from>'
            .'<as-path>bir-yol</as-path><protocol>bgp</protocol><neighbor>198.18.20.1</neighbor>'
            .'</from><then><accept/></then></term>'
            .'</policy-statement></policy-options>'
        ))[0]['terms'][0];

        $this->assertSame(['bir-yol'], $term['match_conditions']['as_paths']);
        $this->assertSame(['bgp'], $term['match_conditions']['protocols']);
        $this->assertSame(['198.18.20.1'], $term['match_conditions']['neighbors']);
        $this->assertSame([], $term['match_conditions']['residual']);
    }

    /**
     * route-filter üç biçimde gelir: değersiz tür (<orlonger/>), değerli tür
     * (<upto>/24</upto>) ve aralık (prefix-length-range). Cihazda ölçüldü.
     */
    public function test_route_filter_eslesme_turleriyle_okunur(): void
    {
        $term = $this->read($this->reply(
            '<policy-options><policy-statement><name>p</name>'
            .'<term><name>t</name><from>'
            .'<route-filter><address>10.0.0.0/8</address><orlonger/></route-filter>'
            .'<route-filter><address>172.16.0.0/12</address><prefix-length-range>/24-/32</prefix-length-range></route-filter>'
            .'</from><then><accept/></then></term>'
            .'</policy-statement></policy-options>'
        ))[0]['terms'][0];

        $this->assertSame([
            ['address' => '10.0.0.0/8', 'match_type' => 'orlonger', 'value' => null],
            ['address' => '172.16.0.0/12', 'match_type' => 'prefix-length-range', 'value' => '/24-/32'],
        ], $term['match_conditions']['route_filters']);
    }

    /**
     * BGP nitelik eylemleri politikayı salt okunur yapmaz.
     */
    public function test_bgp_nitelikleri_okunur(): void
    {
        $term = $this->read($this->reply(
            '<policy-options><policy-statement><name>p</name>'
            .'<term><name>t</name><then>'
            .'<local-preference><local-preference>200</local-preference></local-preference>'
            .'<metric><add/><metric>10</metric></metric>'
            .'<as-path-prepend>65000 65000</as-path-prepend>'
            .'<community><add/><community-name>topluluk-a</community-name></community>'
            .'<accept/></then></term>'
            .'</policy-statement></policy-options>'
        ))[0]['terms'][0];

        $this->assertSame(
            ['operation' => 'set', 'value' => 200, 'nested' => true],
            $term['then_actions']['local_preference'],
        );
        $this->assertSame(
            ['operation' => 'add', 'value' => 10, 'nested' => true],
            $term['then_actions']['metric'],
        );
        $this->assertSame('65000 65000', $term['then_actions']['as_path_prepend']);
        $this->assertSame([['operation' => 'add', 'name' => 'topluluk-a']], $term['then_actions']['communities']);
        $this->assertSame(PolicyAction::Accept, $term['action']);
        $this->assertSame([], $term['then_actions']['residual']);
    }

    /**
     * Junos'ta "next" bir ARGÜMAN alır: `then next policy;`.
     */
    public function test_next_policy_ve_next_term_okunur(): void
    {
        foreach (['policy' => PolicyAction::NextPolicy, 'term' => PolicyAction::NextTerm] as $argument => $beklenen) {
            $term = $this->read($this->reply(
                '<policy-options><policy-statement><name>p</name>'
                ."<term><name>t</name><then><next>{$argument}</next></then></term>"
                .'</policy-statement></policy-options>'
            ))[0]['terms'][0];

            $this->assertSame($beklenen, $term['action']);
        }
    }

    /**
     * EN ÖNEMLİSİ: panelin TANIMADIĞI satır kaybolmaz.
     *
     * Politika güncellenirken silinip yeniden yazılıyor; modellenmeyen satır
     * ham XML olarak saklanır ve geri yazılırken aynen basılır. Politika da
     * salt okunur olmaz.
     */
    public function test_taninmayan_satirlar_ham_haliyle_korunur(): void
    {
        $statement = $this->read($this->reply(
            '<policy-options><policy-statement><name>karmasik</name>'
            .'<uydurma-kapsayici>x</uydurma-kapsayici>'
            .'<term><name>t</name>'
            .'<from><uydurma-kosul>y</uydurma-kosul></from>'
            .'<then><uydurma-eylem>z</uydurma-eylem><accept/></then>'
            .'<uydurma-terim-alani>q</uydurma-terim-alani>'
            .'</term>'
            .'</policy-statement></policy-options>'
        ))[0];

        $this->assertTrue($statement['is_manageable'], 'Artık salt okunur yapılmıyor.');
        $this->assertSame(['<uydurma-kapsayici>x</uydurma-kapsayici>'], $statement['residual_xml']);

        $term = $statement['terms'][0];

        $this->assertSame(['<uydurma-kosul>y</uydurma-kosul>'], $term['match_conditions']['residual']);
        $this->assertSame(['<uydurma-eylem>z</uydurma-eylem>'], $term['then_actions']['residual']);
        $this->assertSame(['<uydurma-terim-alani>q</uydurma-terim-alani>'], $term['residual_xml']);
    }

    /**
     * Çözümlenemeyen bir DÜĞÜM de artığa düşer: yarısını anlayıp yazmak
     * kalanını silerdi.
     */
    public function test_cozumlenemeyen_taninmis_dugum_artiga_duser(): void
    {
        $term = $this->read($this->reply(
            '<policy-options><policy-statement><name>p</name>'
            .'<term><name>t</name><from>'
            .'<route-filter><address>10.0.0.0/8</address><bilinmeyen-tur/></route-filter>'
            .'</from><then><accept/></then></term>'
            .'</policy-statement></policy-options>'
        ))[0]['terms'][0];

        $this->assertSame([], $term['match_conditions']['route_filters']);
        $this->assertStringContainsString('bilinmeyen-tur', $term['match_conditions']['residual'][0]);
    }

    /**
     * Yalnızca ad ve terim içeren politika da yönetilebilir kalmalı.
     */
    public function test_yalnizca_terim_iceren_politika_yonetilebilir(): void
    {
        $statements = $this->read($this->reply(
            '<policy-options><policy-statement><name>gelen</name>'
            .'<term><name>a</name><then><accept/></then></term>'
            .'<term><name>b</name><then><reject/></then></term>'
            .'</policy-statement></policy-options>'
        ));

        $this->assertTrue($statements[0]['is_manageable']);
    }

    public function test_politika_yoksa_bos_doner(): void
    {
        $this->assertSame([], $this->read($this->reply('<policy-options/>')));
    }

    /**
     * invert-match tanımın anlamını tersine çevirir; okunmazsa güncellemede
     * sessizce siliniyordu.
     */
    public function test_community_invert_match_okunur(): void
    {
        $communities = $this->readCommunities($this->reply(
            '<policy-options><community><name>ters</name>'
            .'<members>65000:1</members><invert-match/></community></policy-options>'
        ));

        $this->assertSame('ters', $communities[0]['name']);
        $this->assertSame(['65000:1'], $communities[0]['members']);
        $this->assertTrue($communities[0]['invert_match']);
    }

    /**
     * as-path tanımı: policy-options as-path <ad> { path "<ifade>"; }
     */
    public function test_as_path_tanimlari_okunur(): void
    {
        $paths = $this->readAsPaths($this->reply(
            '<policy-options><as-path><name>musteri1-den</name>'
            .'<path>.* 65000</path></as-path></policy-options>'
        ));

        $this->assertSame([['name' => 'musteri1-den', 'path' => '.* 65000', 'residual_xml' => []]], $paths);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $reply): array
    {
        [$reader, $session] = $this->reader($reply);

        return $reader->statements($session);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readCommunities(string $reply): array
    {
        [$reader, $session] = $this->reader($reply);

        return $reader->communities($session);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readAsPaths(string $reply): array
    {
        [$reader, $session] = $this->reader($reply);

        return $reader->asPaths($session);
    }

    /**
     * @return array{0: PolicyStatementReader, 1: NetconfSession}
     */
    private function reader(string $reply): array
    {
        $transport = new FakeNetconfTransport([$this->hello(), $reply]);
        $session = new NetconfSession($transport, new FramingNegotiator);
        $session->handshake(['urn:ietf:params:netconf:base:1.0']);

        return [new PolicyStatementReader, $session];
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
