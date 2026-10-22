<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Enums\FirewallFamily;
use App\Models\NetworkSwitch;
use App\Models\SwitchFirewallFilter;
use App\Models\SwitchFirewallPolicer;
use App\Models\SwitchFirewallTerm;
use App\Models\SwitchPrefixList;
use App\Models\SwitchRoutingInstance;
use App\Models\User;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Firewall filtresinin TAMAMI panelden yönetilebilir.
 *
 * Tanınmayan bir satır filtreyi "salt okunur" yapmaz; yapsaydı kullanıcı onu
 * hiç yönetemezdi. İki katman var ve bu testler ikisini de kilitler:
 *
 *   1. Modellenen alanlar cihaza gerçekten yazılıyor (üç aile, filtre
 *      seviyesi, genişletilmiş koşullar ve eylemler, policer'lar).
 *   2. Modellenmeyen satır ham hâliyle GERİ YAZILIYOR — hiçbir satır
 *      kaybolmuyor.
 *
 * Kabul edilen biçimler test1'de commit check ile ölçüldü (commit atılmadı).
 */
final class FirewallFullManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_interface_specific_filtre_seviyesinde_yazilir(): void
    {
        $device = $this->device();

        $this->filterAdd(['interface_specific' => '1'])->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<filter><name>koruma</name><interface-specific/>',
            $device->payload(),
        );
    }

    /**
     * EX'te filtre anahtarlama düzleminde de kurulabilir; panel bu aileyi hiç
     * yazamıyordu.
     */
    public function test_ethernet_switching_ailesi_yazilir(): void
    {
        $device = $this->device();

        $this->filterAdd([
            'family' => 'ethernet-switching',
            'ether_types' => ['arp'],
            'source_mac_addresses' => '00:11:22:33:44:55',
        ])->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<firewall><family><ethernet-switching>', $payload);
        $this->assertStringContainsString('<ether-type>arp</ether-type>', $payload);
        $this->assertStringContainsString(
            '<source-mac-address><name>00:11:22:33:44:55</name></source-mac-address>',
            $payload,
        );
    }

    public function test_genisletilmis_kosullar_yazilir(): void
    {
        $device = $this->device();

        $this->filterAdd([
            'protocols' => ['tcp'],
            'tcp_flags' => 'syn',
            'dscps' => ['ef'],
            'packet_lengths' => '1400',
            'ttls' => '10-20',
            'is_fragment' => '1',
            'ip_options' => ['router-alert'],
            'precedences' => ['routine'],
            'interfaces' => 'ge-0/0/5.0',
            'icmp_codes' => '3',
        ])->assertSessionHasNoErrors();

        $payload = $device->payload();

        foreach ([
            '<tcp-flags>syn</tcp-flags>',
            '<dscp>ef</dscp>',
            '<packet-length>1400</packet-length>',
            '<is-fragment/>',
            '<ip-options>router-alert</ip-options>',
            '<precedence>routine</precedence>',
            '<interface>ge-0/0/5.0</interface>',
            '<icmp-code>3</icmp-code>',
        ] as $expected) {
            $this->assertStringContainsString($expected, $payload);
        }
    }

    public function test_genisletilmis_eylemler_yazilir(): void
    {
        $switch = $this->switch();
        SwitchFirewallPolicer::factory()->for($switch, 'switch')->create(['name' => 'hizli']);

        /*
         * "then routing-instance" artık CİHAZDA TANIMLI bir tabloya atıf
         * yapmak zorunda; olmayan bir ad commit check'te düşerdi.
         */
        SwitchRoutingInstance::factory()->for($switch, 'switch')->create(['name' => 'musteri']);

        $device = $this->device();

        $this->filterAdd([
            'action' => '',
            'policer' => 'hizli',
            'forwarding_class' => 'best-effort',
            'loss_priority' => 'low',
            'dscp' => 'ef',
            'routing_instance' => 'musteri',
            'analyzer' => 'ayna',
            'is_syslogged' => '1',
            'is_sampled' => '1',
            'is_port_mirrored' => '1',
        ], $switch)->assertSessionHasNoErrors();

        $payload = $device->payload();

        foreach ([
            '<policer>hizli</policer>',
            '<forwarding-class>best-effort</forwarding-class>',
            '<loss-priority>low</loss-priority>',
            '<dscp>ef</dscp>',
            '<routing-instance>musteri</routing-instance>',
            '<analyzer>ayna</analyzer>',
            '<syslog/>',
            '<sample/>',
            '<port-mirror/>',
        ] as $expected) {
            $this->assertStringContainsString($expected, $payload);
        }
    }

    /**
     * "next term" zinciri bitirmez; sonlandırıcı eylemle birlikte yazılmaz.
     */
    public function test_next_term_yazilir(): void
    {
        $device = $this->device();

        $this->filterAdd(['action' => '', 'goes_to_next_term' => '1', 'count_name' => 'sayac'])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<count>sayac</count><next>term</next>', $payload);
        $this->assertStringNotContainsString('<accept/>', $payload);
    }

    /**
     * "except" koşulu TERSİNE çevirir; yazılmazsa filtre sessizce ters çalışır.
     */
    public function test_prefix_list_except_isareti_yazilir(): void
    {
        $device = $this->device();

        $this->filterAdd([
            'source_prefix_lists' => ['koru'],
            'source_prefix_list_excepts' => ['koru'],
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<source-prefix-list><name>koru</name><except/></source-prefix-list>',
            $device->payload(),
        );
    }

    /**
     * EN ÖNEMLİSİ: panelin göstermediği satırlar düzenlemede KAYBOLMAZ.
     *
     * Filtre seviyesinde de, terimin üç yerinde de (terim, from, then).
     */
    public function test_korunan_ham_satirlar_geri_yazilir(): void
    {
        $switch = $this->switch();

        $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')->create([
            'name' => 'koruma',
            'family' => FirewallFamily::Inet,
            'residual_xml' => ['<physical-interface-filter/>'],
        ]);

        SwitchFirewallTerm::factory()->for($filter, 'filter')->create([
            'name' => 'list',
            'position' => 0,
            'residual_xml' => ['<uydurma-terim/>'],
            'from_residual_xml' => ['<flexible-match-mask><name>x</name></flexible-match-mask>'],
            'then_residual_xml' => ['<uydurma-eylem>1</uydurma-eylem>'],
        ]);

        $device = $this->device();

        $this->actingAs($this->admin())
            ->post(route('switches.firewall.terms.store', [$switch, $filter]), [
                'name' => 'yeni', 'action' => 'discard',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        foreach ([
            '<physical-interface-filter/>',
            '<uydurma-terim/>',
            '<flexible-match-mask><name>x</name></flexible-match-mask>',
            '<uydurma-eylem>1</uydurma-eylem>',
        ] as $protected) {
            $this->assertStringContainsString($protected, $payload, "Korunan satır geri yazılmadı: {$protected}");
        }
    }

    /**
     * Filtre seviyesindeki ayar, terimlere dokunmadan değiştirilebilir ve
     * korunan satırlar yine geri yazılır.
     */
    public function test_filtre_ayari_terimlere_dokunmadan_degistirilir(): void
    {
        $switch = $this->switch();
        $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')
            ->withPreservedLines()->create(['name' => 'koruma', 'interface_specific' => false]);
        SwitchFirewallTerm::factory()->for($filter, 'filter')->create(['name' => 'list', 'position' => 0]);
        $device = $this->device();

        $this->actingAs($this->admin())
            ->put(route('switches.firewall.update', [$switch, $filter]), ['interface_specific' => '1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<interface-specific/>', $payload);
        $this->assertStringContainsString('<term><name>list</name>', $payload);
        $this->assertStringContainsString('<uydurma-satir>x</uydurma-satir>', $payload);
    }

    /**
     * Ailesi uyuşmayan koşul cihazda geçersizdir; panel önce söyler ve isteği
     * cihaza hiç götürmez.
     */
    public function test_ailesi_uymayan_kosul_reddedilir(): void
    {
        $device = $this->device();

        $this->filterAdd(['family' => 'inet', 'next_headers' => ['tcp']])
            ->assertSessionHasErrors('next_headers');

        $this->assertNull($device->connection);
    }

    public function test_ipv4_basligi_kosulu_inet6_filtresinde_reddedilir(): void
    {
        $device = $this->device();

        $this->filterAdd(['family' => 'inet6', 'is_fragment' => '1'])
            ->assertSessionHasErrors('is_fragment');

        $this->assertNull($device->connection);
    }

    public function test_tcp_bayragi_kisayolla_birlikte_reddedilir(): void
    {
        $device = $this->device();

        $this->filterAdd(['tcp_flags' => 'syn', 'is_tcp_initial' => '1'])
            ->assertSessionHasErrors('tcp_flags');

        $this->assertNull($device->connection);
    }

    public function test_next_term_ile_sonuc_birlikte_reddedilir(): void
    {
        $device = $this->device();

        $this->filterAdd(['action' => 'accept', 'goes_to_next_term' => '1'])
            ->assertSessionHasErrors('goes_to_next_term');

        $this->assertNull($device->connection);
    }

    public function test_cihazda_olmayan_policer_secilemez(): void
    {
        $device = $this->device();

        $this->filterAdd(['policer' => 'olmayan'])->assertSessionHasErrors('policer');

        $this->assertNull($device->connection);
    }

    public function test_policer_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->admin())
            ->post(route('switches.firewall.policers.store', $switch), [
                'name' => 'musteri-10m',
                'bandwidth_limit' => '10m',
                'burst_size_limit' => '15k',
                'filter_specific' => '1',
                'is_discard' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<firewall><policer><name>musteri-10m</name>', $payload);
        $this->assertStringContainsString('<filter-specific/>', $payload);
        $this->assertStringContainsString(
            '<if-exceeding><bandwidth-limit>10m</bandwidth-limit><burst-size-limit>15k</burst-size-limit></if-exceeding>',
            $payload,
        );
        $this->assertStringContainsString('<then><discard/></then>', $payload);
    }

    /**
     * Policer güncellemede "replace" ile yazılır: panelden kaldırılan bir
     * sınır cihazda da kalkmalı.
     */
    public function test_policer_replace_ile_guncellenir(): void
    {
        $switch = $this->switch();
        $policer = SwitchFirewallPolicer::factory()->for($switch, 'switch')->create([
            'name' => 'musteri-10m',
            'residual_xml' => ['<uydurma-policer/>'],
        ]);
        $device = $this->device();

        $this->actingAs($this->admin())
            ->put(route('switches.firewall.policers.update', [$switch, $policer]), [
                'name' => 'musteri-10m',
                'bandwidth_percent' => '50',
                'is_discard' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<policer replace="replace">', $payload);
        $this->assertStringContainsString('<bandwidth-percent>50</bandwidth-percent>', $payload);
        $this->assertStringContainsString('<uydurma-policer/>', $payload);
    }

    public function test_mutlak_hiz_ile_yuzde_birlikte_verilemez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->admin())
            ->post(route('switches.firewall.policers.store', $switch), [
                'name' => 'karma',
                'bandwidth_limit' => '10m',
                'bandwidth_percent' => '50',
                'is_discard' => '1',
            ])
            ->assertSessionHasErrors('bandwidth_percent');

        $this->assertNull($device->connection);
    }

    /**
     * Tanımsız policer'a atıf commit'i düşürür; panel önce söyler.
     */
    public function test_kullanilan_policer_silinemez(): void
    {
        $switch = $this->switch();
        $policer = SwitchFirewallPolicer::factory()->for($switch, 'switch')->create(['name' => 'hizli']);
        $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')->create(['name' => 'koruma']);
        SwitchFirewallTerm::factory()->for($filter, 'filter')->create(['name' => 't', 'policer' => 'hizli']);
        $device = $this->device();

        $this->actingAs($this->admin())
            ->delete(route('switches.firewall.policers.destroy', [$switch, $policer]))
            ->assertRedirect();

        $this->assertNull($device->connection);
        $this->assertDatabaseHas('switch_firewall_policers', ['id' => $policer->id]);
    }

    public function test_baska_switchin_policeri_kullanilamaz(): void
    {
        $switch = $this->switch();
        $other = $this->switch('198.18.20.196');
        $foreign = SwitchFirewallPolicer::factory()->for($other, 'switch')->create();
        $device = $this->device();

        $this->actingAs($this->admin())
            ->delete(route('switches.firewall.policers.destroy', [$switch, $foreign]))
            ->assertNotFound();

        $this->assertNull($device->connection);
    }

    /**
     * Ekran, üç ailenin filtresini, policer'ları ve korunan satır bilgisini
     * hatasız basmalı.
     *
     * Blade hataları sunucu testlerinden kolayca kaçar: form alanları yalnızca
     * o pencere açıldığında derlenir. Bu test bütün pencereleri (filtre,
     * terim, ayar, policer) tek istekte derletir.
     */
    public function test_ekran_policer_ve_korunan_satirlarla_acilir(): void
    {
        $switch = $this->switch();
        SwitchFirewallPolicer::factory()->for($switch, 'switch')->create(['name' => 'musteri-10m']);

        foreach (FirewallFamily::cases() as $family) {
            $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')->withPreservedLines()->create([
                'family' => $family,
                'name' => 'koruma-'.str_replace('-', '', $family->value),
                'interface_specific' => true,
            ]);

            SwitchFirewallTerm::factory()->for($filter, 'filter')->create([
                'name' => 't',
                'position' => 0,
                'from_residual_xml' => ['<flexible-match-mask><name>x</name></flexible-match-mask>'],
            ]);
        }

        $this->actingAs($this->admin())
            ->get(route('switches.firewall', $switch))
            ->assertOk()
            ->assertSee('musteri-10m')
            ->assertSee('korunurlar', escape: false)
            ->assertSee('interface-specific', escape: false);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function filterAdd(array $payload, ?NetworkSwitch $switch = null): TestResponse
    {
        $switch ??= $this->switch();

        return $this->actingAs($this->admin())->post(route('switches.firewall.store', $switch), [
            'filter_name' => 'koruma',
            'family' => 'inet',
            'name' => 'kabul',
            'action' => 'accept',
            ...$payload,
        ]);
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice('[edit firewall]');
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function switch(string $ip = '198.18.20.195'): NetworkSwitch
    {
        return once(function () use ($ip): NetworkSwitch {
            $switch = NetworkSwitch::factory()->create(['management_ip' => $ip]);

            $switch->credentials()->create([
                'protocol' => CredentialProtocol::Netconf,
                'username' => 'admin',
                'password' => 'gizli',
                'port' => 830,
            ]);

            SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'koru']);

            return $switch->refresh();
        });
    }

    private function admin(): User
    {
        return once(fn (): User => User::factory()->admin()->create());
    }
}
