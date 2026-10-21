<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * Port ayarları artık veritabanına değil CİHAZA yazılır; yazıldıktan sonra
 * portlar cihazdan geri okunur.
 */
final class SwitchPortEditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Üyeliğin İKİNCİ YOLU da temizlenmeli.
     *
     * ELS öncesi cihazlarda üyelik "vlans <ad> interface <port>.0"
     * satırında da durabilir ve cihaz iki yolu TEK üyelik sayıyor.
     * Panel yalnızca arayüz tarafına yazsaydı porta yeni bir VLAN vermek
     * access portta cihaz reddine düşerdi: "Access interface has more than
     * one vlan member" (test1 üzerinde ölçüldü).
     *
     * Olmayan satırı silmek cihazda hata değildir; bu yüzden hem eski hem
     * yeni üye için temizlik yazılır.
     */
    public function test_vlans_tarafindaki_uyelik_de_temizlenir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/5', 'port_mode' => PortMode::Access]);

        $eski = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 132, 'name' => 'V32']);
        $yeni = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 140, 'name' => 'V40']);

        // Port cihazda V32'ye üye; üyelik vlans tarafında bildirilmiş.
        $port->vlans()->attach($eski, ['is_untagged' => true]);

        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'vlans' => [$yeni->uuid],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $yazilan = implode("\n", $transport->written);

        $this->assertStringContainsString(
            '<vlan><name>V32</name><interface delete="delete"><name>ge-0/0/5.0</name></interface></vlan>',
            $yazilan,
        );
        $this->assertStringContainsString(
            '<vlan><name>V40</name><interface delete="delete"><name>ge-0/0/5.0</name></interface></vlan>',
            $yazilan,
        );
        $this->assertStringContainsString('<members>V40</members>', $yazilan);
    }

    /**
     * ELS'te hız BAŞKA YERDE ve BAŞKA BİÇİMDE durur.
     *
     *   ELS öncesi : ether-options { speed { ethernet-100m; } }
     *   ELS        : interfaces <ad> { speed 100m; }
     *
     * test4 (EX2300-24T, 24.4R2-S4) üzerinde ölçüldü: ELS öncesi biçim ELS
     * cihazda "syntax error" veriyor. Port formu hızı HER KAYITTA gönderdiği
     * için, bu ayrım olmadan ELS bir switchte hiçbir port düzenlemesi
     * çalışmıyordu — açıklama değiştirmek bile reddediliyordu.
     */
    public function test_els_cihazda_hiz_arayuz_altina_duz_yazilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();

        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/0', 'port_mode' => PortMode::Unconfigured]);

        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'description' => 'sunucu odasi',
                'enabled' => '1',
                'port_mode' => 'access',
                'speed_config' => '100',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $yazilan = implode("\n", $transport->written);

        $this->assertStringContainsString('<speed>100m</speed>', $yazilan);
        $this->assertStringNotContainsString('<ethernet-100m/>', $yazilan);
        // Mod da ELS adıyla gitmeli.
        $this->assertStringContainsString('<interface-mode>access</interface-mode>', $yazilan);
    }

    /**
     * ELS'te otomatik anlaşma ayrı bir satırdır: hız satırı silinir,
     * "ether-options auto-negotiation" yazılır. Kapalıyken hız verilmezse
     * cihaz reddediyor ("Configure interfaces <ad> speed, as auto
     * negotiation is disabled"), bu yüzden o durumda satır yalnızca silinir.
     */
    public function test_els_cihazda_otomatik_anlasma_ayri_satirdir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();

        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/0']);
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'enabled' => '1',
                'port_mode' => 'access',
                'speed_config' => '',
                'is_autonegotiation_enabled' => '1',
            ])
            ->assertRedirect();

        $yazilan = implode("\n", $transport->written);

        $this->assertStringContainsString('<ether-options><auto-negotiation/></ether-options>', $yazilan);
        $this->assertStringContainsString('<speed delete="delete"/>', $yazilan);
    }

    public function test_port_ayarlari_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/5', 'port_mode' => PortMode::Unconfigured]);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30, 'name' => 'V30']);

        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'description' => 'sunucu odasi',
                'enabled' => '1',
                'port_mode' => 'access',
                'speed_config' => '1000',
                'vlans' => [$vlan->uuid],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $yazilan = implode("\n", $transport->written);

        $this->assertStringContainsString('<description>sunucu odasi</description>', $yazilan);
        $this->assertStringContainsString('<disable delete="delete"/>', $yazilan);
        $this->assertStringContainsString('<port-mode>access</port-mode>', $yazilan);
        $this->assertStringContainsString('<ethernet-1g/>', $yazilan);

        // Üyelik değiştiğinde önce eski liste silinir, sonra yenisi yazılır:
        // Junos "merge" listeyi değiştirmez, üstüne ekler.
        $this->assertStringContainsString('<vlan delete="delete"/>', $yazilan);
        $this->assertStringContainsString('<members>V30</members>', $yazilan);
    }

    /**
     * Access portta seçilen tek VLAN zaten etiketsizdir; cihaza bir kez
     * yazılır, ayrıca etiketsiz alanı sorulmaz.
     */
    public function test_access_portta_tek_vlan_bir_kez_yazilir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/5']);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30, 'name' => 'V30']);

        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'vlans' => [$vlan->uuid],
            ])
            ->assertRedirect();

        $this->assertSame(1, substr_count(implode('', $transport->written), '<members>V30</members>'));
    }

    /**
     * Cihaz access portta ikinci üyeyi reddediyor; form da izin vermez.
     */
    public function test_access_portta_ikinci_vlan_reddedilir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/5']);
        $bir = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30]);
        $iki = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 31]);

        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'vlans' => [$bir->uuid, $iki->uuid],
            ])
            ->assertSessionHasErrors('vlans');

        $this->assertNull($transport->connection);
    }

    public function test_access_portta_etiketsiz_vlan_ayrica_secilemez(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/5']);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30]);

        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'vlans' => [$vlan->uuid],
                'untagged_vlan' => $vlan->uuid,
            ])
            ->assertSessionHasErrors('untagged_vlan');

        $this->assertNull($transport->connection);
    }

    /**
     * Trunk portta etiketsiz VLAN seçilenlerden biri olmalı ve üyelikte
     * yalnızca bir kez yazılmalı.
     */
    public function test_trunk_portta_etiketsiz_vlan_uyelige_eklenir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/5']);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30, 'name' => 'V30']);

        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'trunk',
                'vlans' => [$vlan->uuid],
                'untagged_vlan' => $vlan->uuid,
            ])
            ->assertRedirect();

        $yazilan = implode('', $transport->written);

        $this->assertStringContainsString('<port-mode>trunk</port-mode>', $yazilan);
        $this->assertSame(1, substr_count($yazilan, '<members>V30</members>'));
    }

    /**
     * PoE donanımı olmayan porta PoE satırı yazılmaz; o portlarda alan zaten
     * gösterilmez ama form yine de gönderilebilir.
     */
    public function test_poe_donanimi_olmayan_porta_poe_yazilmaz(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/12', 'is_poe_enabled' => null]);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30]);

        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'vlans' => [$vlan->uuid],
                'is_poe_enabled' => '1',
            ])
            ->assertRedirect();

        $this->assertStringNotContainsString('<poe>', implode('', $transport->written));
    }

    /**
     * Hız ile auto-negotiation aynı ayardır: hız boşsa otomatik yazılır.
     */
    public function test_hiz_bos_birakilirsa_auto_negotiation_yazilir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/5']);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30]);

        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'speed_config' => '',
                'vlans' => [$vlan->uuid],
            ])
            ->assertRedirect();

        $this->assertStringContainsString('<auto-negotiation/>', implode('', $transport->written));
    }

    /**
     * Envanteri düzenleyen "update" cihaza yazmaya yetmez.
     */
    public function test_duzenleme_yetkisi_cihaza_yazmaya_yetmez(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create();
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'update']))
            ->put(route('switches.ports.update', [$switch, $port]), ['port_mode' => 'access'])
            ->assertForbidden();

        $this->assertNull($transport->connection);
    }

    public function test_baska_switchin_vlani_atanamaz(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create();
        $foreign = Vlan::factory()->for(NetworkSwitch::factory()->create(), 'switch')->create();
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'vlans' => [$foreign->uuid],
            ])
            ->assertSessionHasErrors('vlans.0');

        $this->assertNull($transport->connection);
    }

    public function test_etiketsiz_vlan_secilenlerden_biri_olmali(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create();
        $secili = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 10]);
        $secilmeyen = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 20]);
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'vlans' => [$secili->uuid],
                'untagged_vlan' => $secilmeyen->uuid,
            ])
            ->assertSessionHasErrors('untagged_vlan');

        $this->assertNull($transport->connection);
    }

    /**
     * Cihaz yalnızca 10/100/1000 kabul ediyor; liste daha fazlasını
     * gösterirse o değerler commit edilemez.
     */
    public function test_cihazin_kabul_etmedigi_hiz_reddedilir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create();
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'speed_config' => '10000',
            ])
            ->assertSessionHasErrors('speed_config');

        $this->assertNull($transport->connection);
    }

    public function test_baska_switchin_portu_bulunamaz(): void
    {
        $switch = $this->switch();
        $foreign = SwitchPort::factory()->for(NetworkSwitch::factory()->create(), 'switch')->create();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $foreign]), ['port_mode' => 'access'])
            ->assertNotFound();
    }

    public function test_ekran_cihaza_yazildigini_soyler(): void
    {
        // Ekran Zabbix'ten trafik okur; testte gerçek istek gitmez.
        Http::fake(['*' => Http::response(['result' => []])]);

        $switch = $this->switch();
        SwitchPort::factory()->for($switch, 'switch')->create();

        $this->actingAs($this->operator(['view', 'configure']))
            ->get(route('switches.ports', $switch))
            ->assertOk()
            ->assertSee('cihaza yazılır', escape: false);
    }

    /**
     * MTU sınırları cihazın kendi cevabıdır: "Value 9217 is not within range
     * (256..9216)" (jn32-test, 12.3R12.4).
     */
    public function test_mtu_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/5', 'port_mode' => PortMode::Access]);
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'mtu' => '9216',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<mtu>9216</mtu>', implode("\n", $transport->written));
    }

    /**
     * BOŞ GÖNDERİLEN MTU silinir; HİÇ GÖNDERİLMEYEN MTU'ya dokunulmaz.
     *
     * Ayrım basit/gelişmiş mod yüzünden önem kazandı: basit modda alan hiç
     * çizilmediği için isteğe de girmez ve kullanıcının GÖRMEDİĞİ bir ayar
     * her kayıtta silinemez. Gelişmiş modda alan görünür, boş gönderilir ve
     * silme bilinçlidir — test o hâli kurar.
     */
    public function test_bos_mtu_cihazdan_silinir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/5', 'port_mode' => PortMode::Access, 'mtu' => 9216]);
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), ['port_mode' => 'access', 'mtu' => ''])
            ->assertRedirect();

        $this->assertStringContainsString('<mtu delete="delete"/>', implode("\n", $transport->written));
    }

    public function test_aralik_disi_mtu_reddedilir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/5', 'port_mode' => PortMode::Access]);
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'mtu' => '9217',
            ])
            ->assertSessionHasErrors('mtu');

        $this->assertNull($transport->connection);
    }

    /**
     * ae ÜYESİ portta MTU yazılamaz: cihaz "ae child device mtu setting and
     * vlan-tagging is not allowed" diyor. MTU o durumda ae arayüzünün
     * kendisine verilir.
     */
    public function test_ae_uyesi_portta_mtu_verilemez(): void
    {
        $switch = $this->switch();
        $ae = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ae0']);
        $port = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'port_mode' => PortMode::Access,
            'aggregate_port_id' => $ae->id,
        ]);
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'mtu' => '9216',
            ])
            ->assertSessionHasErrors('mtu');

        $this->assertNull($transport->connection);
    }

    /**
     * Duplex cihazda "ether-options link-mode"tur ve hızla AYNI düğümde
     * durur; ikisi ayrı belgede gönderilmemeli.
     */
    public function test_duplex_hizla_ayni_dugumde_yazilir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/5', 'port_mode' => PortMode::Access]);
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'speed_config' => '100',
                'duplex_config' => 'full',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = implode("\n", $transport->written);

        $this->assertStringContainsString('<ether-options>', $yazilan);
        $this->assertStringContainsString('<ethernet-100m/>', $yazilan);
        $this->assertStringContainsString('<link-mode>full-duplex</link-mode>', $yazilan);
        // Tek eleman: iki ayrı ether-options bloğu yazılmamalı.
        $this->assertSame(1, substr_count($yazilan, '<ether-options>'));
    }

    /**
     * Cihaz: "Half duplex is an invalid configuration when speed is
     * 10G/1G/Auto". Kısıt panelde de durmalı.
     */
    public function test_half_duplex_otomatik_hizla_reddedilir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/5', 'port_mode' => PortMode::Access]);
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'duplex_config' => 'half',
            ])
            ->assertSessionHasErrors('duplex_config');

        $this->assertNull($transport->connection);
    }

    public function test_half_duplex_bin_mbps_ile_reddedilir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/5', 'port_mode' => PortMode::Access]);
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'speed_config' => '1000',
                'duplex_config' => 'half',
            ])
            ->assertSessionHasErrors('duplex_config');

        $this->assertNull($transport->connection);
    }

    public function test_half_duplex_yuz_mbps_ile_yazilir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/5', 'port_mode' => PortMode::Access]);
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'speed_config' => '100',
                'duplex_config' => 'half',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = implode("\n", $transport->written);

        $this->assertStringContainsString('<link-mode>half-duplex</link-mode>', $yazilan);

        /*
         * Cihaz: "Half duplex and flow control enable is an invalid
         * configuration." 802.3x duraklatma zaten yalnızca full-duplex'te
         * anlamlıdır; half seçilince akış kontrolü aynı belgede kapatılır.
         */
        $this->assertStringContainsString('<no-flow-control/>', $yazilan);
    }

    /**
     * BOŞ GÖNDERİLEN duplex silinir; HİÇ GÖNDERİLMEYEN duplex'e dokunulmaz.
     * Gerekçesi MTU ile aynı (bkz. test_bos_mtu_cihazdan_silinir).
     */
    public function test_bos_duplex_cihazdan_silinir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/5', 'port_mode' => PortMode::Access, 'duplex_config' => 'full']);
        $transport = $this->fakeTransport();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.ports.update', [$switch, $port]), ['port_mode' => 'access', 'duplex_config' => ''])
            ->assertRedirect();

        $this->assertStringContainsString(
            '<link-mode delete="delete"/>',
            implode("\n", $transport->written),
        );
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    /**
     * @param  list<string>  $actions
     */
    private function operator(array $actions): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', $actions);

        /*
         * Cihaz nesnelerinin (port, VLAN, route...) kendi yetkileri var;
         * switch yetkisi onları kapsamıyor. "configure" cihazın kendi
         * ayarlarıdır ve testin niyeti "cihaza yazabilen kullanıcı"dır;
         * "update" ise envanter kaydını düzenlemektir ve cihaza yazdırmaz.
         */
        in_array('configure', $actions, true)
            ? $this->grantDeviceWrite($user)
            : $this->grantDeviceView($user);

        return $user->fresh();
    }

    /**
     * Yazma iki oturumdur; ikinci oturumun sonunda portlar cihazdan geri
     * okunur. Sıra: oturum 1 (hello, open, iki yükleme, fark, check, commit,
     * close), oturum 2 (hello, open, commit, close, arayüz durumu, arayüz
     * yapılandırması).
     */
    private function fakeTransport(): FakeNetconfTransport
    {
        $transport = new FakeNetconfTransport([
            $this->hello(),
            $this->ok(), $this->ok(), $this->ok(),
            $this->diff('[edit interfaces ge-0/0/5]'),
            $this->ok(), $this->ok(), $this->ok(),
            $this->hello(),
            $this->ok(), $this->ok(), $this->ok(),
            $this->interfaceStatus(),
            $this->interfaceConfig(),

            /*
             * PoE ayrı bir RPC'dir. Yanıt listesi burada bitse okuyucu
             * istisnayı yutardı (PoE bilgisi olmayan cihaz olağan); yanıt
             * verilmesi akışı gerçeğe yaklaştırır.
             */
            $this->poe(),

            /*
             * Yazma sonrası ADRES envanteri de tazeleniyor: port ekranından
             * adres verilebiliyor ve yalnızca portları okumak, ekranın cihazda
             * duran adresi göstermemesine yol açardı.
             */
            $this->interfaceConfig(),
        ]);

        $this->app->instance(NetconfTransport::class, $transport);

        return $transport;
    }

    private function hello(): string
    {
        return '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .'<capability>urn:ietf:params:netconf:base:1.0</capability></capabilities></hello>'."\n]]>]]>";
    }

    private function ok(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><ok/></rpc-reply>'."\n]]>]]>";
    }

    private function diff(string $text): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration-information>'
            .'<configuration-output>'.htmlspecialchars($text, ENT_XML1).'</configuration-output>'
            .'</configuration-information></rpc-reply>'."\n]]>]]>";
    }

    private function poe(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0">'
            .'<poe-interface-information/></rpc-reply>'."\n]]>]]>";
    }

    private function interfaceStatus(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><interface-information>'
            .'<physical-interface><name>ge-0/0/5</name><admin-status>up</admin-status>'
            .'<oper-status>down</oper-status><snmp-index>530</snmp-index></physical-interface>'
            .'</interface-information></rpc-reply>'."\n]]>]]>";
    }

    private function interfaceConfig(): string
    {
        return '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration>'
            .'<interfaces><interface><name>ge-0/0/5</name><description>sunucu odasi</description>'
            .'<unit><name>0</name><family><ethernet-switching><vlan><members>V30</members></vlan>'
            .'</ethernet-switching></family></unit></interface></interfaces>'
            .'<vlans><vlan><name>V30</name><vlan-id>30</vlan-id></vlan></vlans>'
            .'</configuration></rpc-reply>'."\n]]>]]>";
    }
}
