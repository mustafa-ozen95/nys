<?php

declare(strict_types=1);

namespace Tests\Feature\Bgp;

use App\Enums\BgpGroupType;
use App\Enums\PolicyAction;
use App\Enums\PrefixMatchType;
use App\Models\BgpGroup;
use App\Models\BgpNeighbor;
use App\Models\NetworkSwitch;
use App\Models\SwitchPolicyStatement;
use App\Models\SwitchPolicyTerm;
use App\Models\SwitchPrefixList;
use App\Models\SwitchPrefixListEntry;
use App\Services\Bgp\PeerConfigurationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Karşı tarafa gönderilen config.
 *
 * Configure modunda kopyala-yapıştır ile çalışmak zorunda: blok yalnızca
 * "set" satırı içerir ve bizim tarafın aynasıdır.
 */
final class PeerConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ebgp_configi_bizim_tarafin_aynasidir(): void
    {
        $neighbor = $this->neighbor();

        $lines = $this->build($neighbor)['lines'];

        // Bizim komşu adresimiz onların local-address'i.
        $this->assertContains('set protocols bgp group omurga local-address 198.18.8.37', $lines);
        // Bizim yerel adresimiz onların neighbor'ı.
        $this->assertContains('set protocols bgp group omurga neighbor 198.18.8.38', $lines);
        // Bizim AS'imiz onların peer-as'ı.
        $this->assertContains('set protocols bgp group omurga peer-as 65000', $lines);
        // Onların AS'i kendi autonomous-system'leri.
        $this->assertContains('set routing-options autonomous-system 65001', $lines);
        $this->assertContains('set protocols bgp group omurga type external', $lines);
    }

    /**
     * Yapıştırılan blokta yalnızca komut olmalı: Junos configure modu yorum
     * kabul etmez ve araya karışan tek satır bütün yapıştırmayı bozar.
     */
    public function test_blokta_yalnizca_set_satirlari_bulunur(): void
    {
        foreach ($this->build($this->neighbor())['lines'] as $line) {
            $this->assertStringStartsWith('set ', $line);
        }
    }

    public function test_ibgp_turu_korunur(): void
    {
        $neighbor = $this->neighbor(['type' => BgpGroupType::Internal, 'peer_asn' => null]);
        $neighbor->update(['peer_asn' => '65000']);

        $lines = $this->build($neighbor->fresh())['lines'];

        $this->assertContains('set protocols bgp group omurga type internal', $lines);
    }

    public function test_multihop_karsi_tarafa_da_yazilir(): void
    {
        $neighbor = $this->neighbor(['multihop' => true, 'multihop_ttl' => 5]);

        $this->assertContains(
            'set protocols bgp group omurga multihop ttl 5',
            $this->build($neighbor)['lines'],
        );
    }

    public function test_ttlsiz_multihop_ttlsiz_yazilir(): void
    {
        $neighbor = $this->neighbor(['multihop' => true, 'multihop_ttl' => null]);

        $this->assertContains(
            'set protocols bgp group omurga multihop',
            $this->build($neighbor)['lines'],
        );
    }

    /**
     * passive AYNALANMAZ: bizde pasifse oturumu onlar başlatmalı, yani
     * onlarda passive yazılmamalı.
     */
    public function test_passive_aynalanmaz_not_dusulur(): void
    {
        $neighbor = $this->neighbor(['passive' => true]);

        $result = $this->build($neighbor);

        $this->assertNotContains('set protocols bgp group omurga passive', $result['lines']);
        $this->assertNotEmpty(array_filter(
            $result['notes'],
            static fn (string $note): bool => str_contains($note, 'pasif'),
        ));
    }

    public function test_komsu_seviyesindeki_degerler_grubu_ezer(): void
    {
        $neighbor = $this->neighbor();
        $neighbor->update(['local_address' => '10.9.9.1', 'peer_asn' => '65123']);

        $lines = $this->build($neighbor->fresh())['lines'];

        $this->assertContains('set protocols bgp group omurga neighbor 10.9.9.1', $lines);
        $this->assertContains('set routing-options autonomous-system 65123', $lines);
    }

    /**
     * Anons politikası keyfi yazılmaz: bizim KABUL ETTİĞİMİZ prefixler,
     * onların anons etmesi gerekenlerdir.
     */
    public function test_anons_politikasi_kabul_ettigimiz_prefixlerden_uretilir(): void
    {
        $neighbor = $this->neighbor();
        $switch = $neighbor->switch;

        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'MUSTERI']);
        SwitchPrefixListEntry::factory()->for($list, 'prefixList')
            ->create(['address' => '192.0.2.0', 'prefix_length' => 24]);

        $policy = SwitchPolicyStatement::factory()->for($switch, 'switch')->create(['name' => 'gelen']);
        SwitchPolicyTerm::factory()->for($policy, 'statement')->create([
            'name' => 'A',
            'match_prefix_lists' => ['MUSTERI'],
            'match_type' => PrefixMatchType::OrLonger,
            'action' => PolicyAction::Accept,
        ]);

        $neighbor->group->update(['import_policies' => ['gelen']]);

        $lines = $this->build($neighbor->fresh())['lines'];

        $this->assertContains('set policy-options prefix-list OMURGA-PREFIXES 192.0.2.0/24', $lines);
        // Eşleşme türü Junos'ta zorunludur.
        $this->assertContains(
            'set policy-options policy-statement anons-omurga term anons from prefix-list-filter OMURGA-PREFIXES orlonger',
            $lines,
        );
        $this->assertContains('set protocols bgp group omurga export anons-omurga', $lines);
    }

    public function test_prefix_bilinmiyorsa_anons_politikasi_uretilmez(): void
    {
        $result = $this->build($this->neighbor());

        $this->assertEmpty(array_filter(
            $result['lines'],
            static fn (string $line): bool => str_contains($line, 'policy-statement'),
        ));
        $this->assertNotEmpty($result['notes']);
    }

    /**
     * Eksik veriyle yanlış satır üretmek, satırı hiç üretmemekten kötüdür.
     */
    public function test_eksik_veri_uydurulmaz(): void
    {
        $switch = NetworkSwitch::factory()->create(['autonomous_system' => null]);
        $group = BgpGroup::factory()->for($switch, 'switch')
            ->create(['name' => 'omurga', 'local_address' => null, 'peer_asn' => null]);
        $neighbor = BgpNeighbor::factory()->for($group, 'group')->for($switch, 'switch')
            ->create(['address' => '198.18.8.37', 'peer_asn' => null]);

        $result = $this->build($neighbor);

        $this->assertFalse($result['complete']);
        $this->assertCount(3, array_filter(
            $result['notes'],
            static fn (string $note): bool => str_contains($note, 'tanımlı değil'),
        ));
        $this->assertEmpty(array_filter(
            $result['lines'],
            static fn (string $line): bool => str_contains($line, 'peer-as')
                || str_contains($line, 'autonomous-system')
                || str_contains($line, 'neighbor'),
        ));
    }

    public function test_ipv6_komsusu_desteklenir(): void
    {
        $neighbor = $this->neighbor();
        $neighbor->update(['address' => '2001:db8:0:230::2']);

        $lines = $this->build($neighbor->fresh())['lines'];

        $this->assertContains(
            'set protocols bgp group omurga local-address 2001:db8:0:230::2',
            $lines,
        );
    }

    /**
     * Ayarlar KOMŞU seviyesinde de yazılabilir ve grubunkini ezer. Yalnızca
     * gruba bakan bir üretici, komşuya özel açılmış multihop'u karşı tarafa
     * yazmaz ve oturum tek taraflı kalırdı.
     */
    public function test_komsu_seviyesindeki_ayarlar_da_aynalanir(): void
    {
        $neighbor = $this->neighbor();
        $neighbor->update(['multihop' => true, 'multihop_ttl' => 3, 'hold_time' => 45]);

        $lines = $this->build($neighbor->fresh())['lines'];

        $this->assertContains('set protocols bgp group omurga multihop ttl 3', $lines);
        $this->assertContains('set protocols bgp group omurga hold-time 45', $lines);
    }

    /**
     * BFD tek taraflı çalışmaz: karşı tarafta açılmazsa oturum kurulur ama
     * BFD hiç kalkmaz ve kimse fark etmez.
     */
    public function test_bfd_karsi_tarafa_da_yazilir(): void
    {
        $neighbor = $this->neighbor(['bfd_minimum_interval' => 300]);

        $this->assertContains(
            'set protocols bgp group omurga bfd-liveness-detection minimum-interval 300',
            $this->build($neighbor)['lines'],
        );
    }

    /**
     * Anahtar iki tarafta AYNI olmalı ama METNE KONMAZ: cihazdan okunan
     * biçim çözülebilir ve bu metin karşı tarafa gönderiliyor.
     */
    public function test_oturum_anahtari_karsi_tarafa_yazilmaz_ama_hatirlatilir(): void
    {
        $neighbor = $this->neighbor(['authentication_key' => '$9$cokGizli']);

        $result = $this->build($neighbor);

        $this->assertStringNotContainsString('cokGizli', implode("\n", $result['lines']));
        $this->assertNotEmpty(array_filter(
            $result['notes'],
            fn (string $note): bool => str_contains($note, 'MD5'),
        ));
    }

    public function test_adres_ailesi_karsi_tarafa_yazilir(): void
    {
        $neighbor = $this->neighbor();
        $neighbor->group->families()->create(['family' => 'inet6', 'nlri' => 'unicast']);

        $this->assertContains(
            'set protocols bgp group omurga family inet6 unicast',
            $this->build($neighbor->fresh())['lines'],
        );
    }

    public function test_bizim_taraf_configi_uretilir(): void
    {
        $neighbor = $this->neighbor([
            'name' => 'erisim',
            'hold_time' => 30,
            'cluster' => '10.0.0.1',
            'remove_private' => true,
            'multipath' => true,
            'out_delay' => 15,
            'allow_prefixes' => ['10.9.0.0/24'],
        ]);

        $lines = app(PeerConfigurationBuilder::class)->ourSide($neighbor)['lines'];

        $this->assertContains('set routing-options autonomous-system 65000', $lines);
        $this->assertContains('set protocols bgp group erisim type external', $lines);
        $this->assertContains('set protocols bgp group erisim peer-as 65001', $lines);
        $this->assertContains('set protocols bgp group erisim local-address 198.18.8.38', $lines);
        $this->assertContains('set protocols bgp group erisim hold-time 30', $lines);
        $this->assertContains('set protocols bgp group erisim cluster 10.0.0.1', $lines);
        $this->assertContains('set protocols bgp group erisim remove-private', $lines);
        $this->assertContains('set protocols bgp group erisim multipath', $lines);
        $this->assertContains('set protocols bgp group erisim out-delay 15', $lines);
        $this->assertContains('set protocols bgp group erisim allow 10.9.0.0/24', $lines);
        $this->assertContains('set protocols bgp group erisim neighbor 198.18.8.37', $lines);

        foreach ($lines as $line) {
            $this->assertStringStartsWith('set ', $line);
        }
    }

    /**
     * Bizim taraf metni ekranda görünür ve kopyalanabilir; anahtar oraya
     * girmemeli. Yerine tutucu konur ki satırın varlığı kaybolmasın.
     */
    public function test_bizim_taraf_configinde_anahtar_yerine_tutucu_durur(): void
    {
        $neighbor = $this->neighbor(['authentication_key' => '$9$cokGizli']);

        $result = app(PeerConfigurationBuilder::class)->ourSide($neighbor);
        $text = implode("\n", $result['lines']);

        $this->assertStringNotContainsString('cokGizli', $text);
        $this->assertStringContainsString('authentication-key "<ANAHTAR>"', $text);
        $this->assertNotEmpty($result['notes']);
    }

    /**
     * @param  array<string, mixed>  $groupAttributes
     */
    private function neighbor(array $groupAttributes = []): BgpNeighbor
    {
        $switch = NetworkSwitch::factory()->create([
            'hostname' => 'omurga',
            'autonomous_system' => '65000',
        ]);

        // "+" solu korur; override edilebilmesi için sağ taraf öncelikli.
        $group = BgpGroup::factory()->for($switch, 'switch')->create($groupAttributes + [
            'name' => 'erisim',
            'type' => BgpGroupType::External,
            'peer_asn' => '65001',
            'local_address' => '198.18.8.38',
        ]);

        return BgpNeighbor::factory()->for($group, 'group')->for($switch, 'switch')
            ->create(['address' => '198.18.8.37', 'peer_asn' => null]);
    }

    /**
     * @return array{lines: list<string>, interface: list<string>, notes: list<string>, complete: bool}
     */
    private function build(BgpNeighbor $neighbor): array
    {
        return app(PeerConfigurationBuilder::class)->forNeighbor($neighbor);
    }
}
