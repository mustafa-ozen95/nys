<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\AdminStatus;
use App\Enums\CredentialProtocol;
use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Enums\PortStatus;
use App\Enums\PortType;
use App\Models\NetworkSwitch;
use App\Models\SwitchIpAddress;
use App\Models\SwitchPort;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Framing\FramingNegotiator;
use App\Services\Netconf\NetconfSession;
use App\Services\Netconf\PortReader;
use App\Services\Netconf\PortSynchronizer;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * VLAN ekranı cihaza yazar: VLAN tanımı ve port üyeliği aynı commit'te gider.
 */
final class VlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_vlan_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'kamera',
                'vlan_tag' => 30,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertStringContainsString('<name>kamera</name>', $device->payload());
        $this->assertStringContainsString('<vlan-id>30</vlan-id>', $device->payload());
    }

    /**
     * Etiket ZORUNLU DEĞİLDİR.
     *
     * Cihaz "vlans <ad>" satırını vlan-id olmadan kabul ediyor ve etiketsiz
     * VLAN'lar (L3 adresi taşıyanlar da dahil) cihazlarda bulunabilir. Form
     * etiketi dayatsaydı, cihazdan okunan böyle bir VLAN panelden hiç
     * düzenlenemezdi.
     *
     * Boş etiket "dokunma" değil "SİL" demektir; merge yalnızca eklediği için
     * silme açıkça istenmezse cihazdaki eski etiket olduğu yerde kalırdı.
     */
    public function test_etiketsiz_vlan_kaydedilebilir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'V27',
                'l3_interface' => 'vlan.28',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<name>V27</name>', $payload);
        $this->assertStringContainsString('<vlan-id delete="delete"/>', $payload);
        $this->assertStringContainsString('<l3-interface>vlan.28</l3-interface>', $payload);
        $this->assertStringNotContainsString('<vlan-id>', $payload);
    }

    /**
     * ELS L3 arayüzünü ETİKETSİZ VLAN'a bağlatmıyor.
     *
     * Cihaz reddediyor: "l3-interface can be configured only under vlans
     * with 'vlan-id'/'vlan-tags'" (test4, EX2300 / 24.4R2-S4'te ölçüldü).
     * Kısıt ELS'e ÖZGÜDÜR — ELS öncesinde etiketsiz VLAN L3 arayüzü
     * taşıyabiliyor — bu yüzden kuralı lehçe söyler, form değil.
     */
    public function test_els_cihazda_l3_arayuzlu_vlan_etiketsiz_olamaz(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();

        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'V27',
                'l3_interface' => 'irb.28',
            ])
            ->assertSessionHasErrors('vlan_tag');

        $this->assertNull($device->connection);
    }

    /**
     * Aynı istek ELS ÖNCESİ cihazda geçerlidir; kısıt yalnızca ELS'e aittir.
     */
    public function test_els_oncesi_cihazda_etiketsiz_vlan_l3_tasiyabilir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'V27',
                'l3_interface' => 'vlan.28',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertStringContainsString('<vlan-id delete="delete"/>', $device->payload());
    }

    /**
     * l3-interface, cihazda "interfaces vlan unit <etiket>" tanımlı değilse
     * commit check'te reddediliyor; ikisi aynı yüklemede gitmeli.
     */
    public function test_l3_arayuzu_verilirse_arayuz_birimi_de_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'kamera',
                'vlan_tag' => 30,
                'l3_interface' => 'vlan.30',
            ])
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<interface><name>vlan</name><unit><name>30</name></unit></interface>', $payload);
        $this->assertStringContainsString('<l3-interface>vlan.30</l3-interface>', $payload);
    }

    /**
     * Porta VLAN atamak, portun üyeliğini DEĞİŞTİRMEKTİR: access portta iki
     * üye olamaz, cihaz commit check'te reddeder.
     */
    public function test_porta_vlan_atamak_uyeligi_degistirir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/5']);
        $old = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 7, 'name' => 'V07']);
        $port->vlans()->attach($old, ['is_untagged' => true]);

        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'kamera',
                'vlan_tag' => 30,
                'ports' => [$port->uuid],
            ])
            ->assertRedirect();

        $payload = $device->payload();

        // Önce mevcut liste silinir, sonra yenisi yazılır.
        $this->assertStringContainsString('<vlan delete="delete"/>', $payload);
        $this->assertStringContainsString('<members>kamera</members>', $payload);

        // Access portta üyelik tek olabilir: eski VLAN yazılmaz, yerini alır.
        $this->assertStringNotContainsString('<members>V07</members>', $payload);
    }

    /**
     * VLAN silinmeden önce üye portlardan çıkarılır; referans kalırsa cihaz
     * commit'i reddeder.
     */
    public function test_vlan_silinmeden_once_portlardan_cikarilir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/5']);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30, 'name' => 'kamera']);
        $port->vlans()->attach($vlan, ['is_untagged' => true]);

        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->delete(route('switches.vlans.destroy', [$switch, $vlan]))
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<vlan delete="delete"/>', $payload);
        $this->assertStringContainsString('<vlan delete="delete"><name>kamera</name></vlan>', $payload);
    }

    /**
     * Cihazda VLAN'ın kimliği adıdır; ad değişirse bütün port üyelikleri
     * kopardı. Panel adı göndermiş olsa bile yok sayılır.
     */
    public function test_ad_degistirilemez(): void
    {
        $switch = $this->switch();
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30, 'name' => 'kamera']);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.vlans.update', [$switch, $vlan]), [
                'name' => 'yeni-ad',
                'vlan_tag' => 31,
            ])
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<name>kamera</name>', $payload);
        $this->assertStringNotContainsString('yeni-ad', $payload);
    }

    public function test_ayni_etiket_bir_switchte_iki_kez_tanimlanamaz(): void
    {
        $switch = $this->switch();
        Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 30]);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), ['name' => 'ikinci', 'vlan_tag' => 30])
            ->assertSessionHasErrors('vlan_tag');

        $this->assertNull($device->connection);
    }

    public function test_ayni_etiket_baska_switchte_bulunabilir(): void
    {
        $switch = $this->switch();
        Vlan::factory()->for(NetworkSwitch::factory()->create(), 'switch')->create(['vlan_tag' => 30]);
        $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), ['name' => 'kamera', 'vlan_tag' => 30])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_aralik_disi_etiket_reddedilir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), ['name' => 'gecersiz', 'vlan_tag' => 5000])
            ->assertSessionHasErrors('vlan_tag');

        $this->assertNull($device->connection);
    }

    public function test_gecersiz_vlan_adi_reddedilir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), ['name' => '30 kamera', 'vlan_tag' => 30])
            ->assertSessionHasErrors('name');

        $this->assertNull($device->connection);
    }

    public function test_baska_switchin_portu_atanamaz(): void
    {
        $switch = $this->switch();
        $foreign = SwitchPort::factory()->for(NetworkSwitch::factory()->create(), 'switch')->create();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'kamera', 'vlan_tag' => 30, 'ports' => [$foreign->uuid],
            ])
            ->assertSessionHasErrors('ports.0');

        $this->assertNull($device->connection);
    }

    public function test_duzenleme_yetkisi_cihaza_yazmaya_yetmez(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'update']))
            ->post(route('switches.vlans.store', $switch), ['name' => 'kamera', 'vlan_tag' => 30])
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    /**
     * Açıklama cihaza da yazılır: terminale düşen biri VLAN'ın ne işe
     * yaradığını panele bakmadan görsün. Yalnızca panelde tutulan bir
     * açıklama, cihazda çalışan için yok demektir.
     */
    public function test_aciklama_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'kamera',
                'vlan_tag' => 30,
                'description' => 'Kamera agi',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<description>Kamera agi</description>', $device->payload());
    }

    /**
     * Boş açıklama "dokunma" değil "sil" demektir: merge yalnızca ekler,
     * silme açıkça istenmezse cihazdaki eski açıklama olduğu yerde kalır ve
     * kullanıcı temizlediğini sanırdı.
     */
    public function test_bos_aciklama_cihazdan_silinir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'kamera',
                'vlan_tag' => 30,
            ])
            ->assertRedirect();

        $this->assertStringContainsString('<description delete="delete"/>', $device->payload());
    }

    /**
     * Cihazda iki VLAN etiket takas ederse eşitleme düşmemeli.
     *
     * Envanterde etiket tekildir. Döngünün ilk adımı, hâlâ eski sahibinde
     * duran etikete çarpar ve BÜTÜN eşitleme geri sarılırdı: panel cihazla
     * bir daha hiç eşleşmezdi. Etiket önce serbest bırakılır.
     */
    public function test_etiket_takasinda_esitleme_dusmez(): void
    {
        $switch = $this->switch();
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'V1', 'vlan_tag' => 10]);
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'V2', 'vlan_tag' => 20]);

        $reader = $this->readerReturningVlans([
            'V1' => ['tag' => 20, 'l3_interface' => null, 'description' => null],
            'V2' => ['tag' => 10, 'l3_interface' => null, 'description' => null],
        ]);

        (new PortSynchronizer($reader))->sync(
            $switch,
            new NetconfSession(new FakeNetconfTransport, new FramingNegotiator),
        );

        $this->assertSame(20, $switch->vlans()->where('name', 'V1')->value('vlan_tag'));
        $this->assertSame(10, $switch->vlans()->where('name', 'V2')->value('vlan_tag'));
    }

    /**
     * Etiketsiz VLAN envantere de girer ve eşitlemede budanmaz: kimlik etiket
     * değil ADDIR.
     */
    public function test_etiketsiz_vlan_envantere_girer(): void
    {
        $switch = $this->switch();

        $reader = $this->readerReturningVlans([
            'V27' => ['tag' => null, 'l3_interface' => 'vlan.28', 'description' => null],
        ]);

        (new PortSynchronizer($reader))->sync(
            $switch,
            new NetconfSession(new FakeNetconfTransport, new FramingNegotiator),
        );

        $vlan = $switch->vlans()->where('name', 'V27')->firstOrFail();

        $this->assertNull($vlan->vlan_tag);
        $this->assertSame('vlan.28', $vlan->l3_interface);
    }

    /**
     * Verilen VLAN listesini döndüren, tek portlu sahte okuyucu.
     *
     * @param  array<string, array{tag: ?int, l3_interface: ?string, description: ?string}>  $vlans
     */
    private function readerReturningVlans(array $vlans): PortReader
    {
        return new class($vlans) extends PortReader
        {
            /** @param  array<string, array{tag: ?int, l3_interface: ?string, description: ?string}>  $vlans */
            public function __construct(private array $vlans) {}

            public function ports(NetconfSession $session): array
            {
                return [
                    'ports' => [[
                        'name' => 'ge-0/0/1',
                        'interface_kind' => PortType::Physical,
                        'if_index' => 501,
                        'description' => null,
                        'mtu' => null,
                        'admin_status' => AdminStatus::Up,
                        'oper_status' => PortStatus::Up,
                        'speed_config' => null,
                        'duplex_config' => null,
                        'is_autonegotiation_enabled' => true,
                        'is_poe_enabled' => null,
                        'port_mode' => PortMode::Unconfigured,
                        'vlans' => [],
                        'untagged' => null,
                        'bundle' => null,
                        'lacp_mode' => null,
                        'lacp_periodic' => null,
                        'minimum_links' => null,
                        'link_speed' => null,
                    ]],
                    'vlans' => $this->vlans,
                    'device_count' => null,
                ];
            }
        };
    }

    /**
     * Cihazdan SON VLAN da silinince envanterde hayalet kayıt kalmamalı.
     *
     * Budama "liste boş değilse" koşuluna bağlıydı. Son VLAN silindiğinde
     * koşul tutmuyor, kayıt envanterde kalıyor ve satırdaki "Düzenle" onu
     * cihazda YENİDEN YARATIYORDU.
     */
    public function test_cihazda_vlan_kalmayinca_envanter_de_bosalir(): void
    {
        $switch = $this->switch();
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ofis', 'vlan_tag' => 100]);

        // Cihaz port döndürüyor ama HİÇ VLAN döndürmüyor: son VLAN silinmiş.
        $reader = new class extends PortReader
        {
            public function ports(NetconfSession $session): array
            {
                return [
                    'ports' => [[
                        'name' => 'ge-0/0/1',
                        'interface_kind' => PortType::Physical,
                        'if_index' => 501,
                        'description' => null,
                        'mtu' => null,
                        'admin_status' => AdminStatus::Up,
                        'oper_status' => PortStatus::Up,
                        'speed_config' => null,
                        'duplex_config' => null,
                        'is_autonegotiation_enabled' => true,
                        'is_poe_enabled' => null,
                        'port_mode' => PortMode::Unconfigured,
                        'vlans' => [],
                        'untagged' => null,
                        'bundle' => null,
                        'lacp_mode' => null,
                        'lacp_periodic' => null,
                        'minimum_links' => null,
                        'link_speed' => null,
                    ]],
                    'vlans' => [],
                    'device_count' => null,
                ];
            }
        };

        (new PortSynchronizer($reader))->sync(
            $switch,
            new NetconfSession(new FakeNetconfTransport, new FramingNegotiator),
        );

        $this->assertSame(0, $switch->vlans()->count());
    }

    /**
     * VLAN silinince L3 arayüz birimi de gider.
     *
     * Bırakılsaydı "interfaces vlan unit 100" hiçbir VLAN'a bağlı olmayan
     * öksüz bir arayüz olarak cihazda kalır, üstündeki IP'yi duyurmaya devam
     * ederdi; panelde ise hiç görünmezdi.
     */
    public function test_vlan_silinince_l3_arayuz_birimi_de_silinir(): void
    {
        $switch = $this->switch();
        $vlan = Vlan::factory()->for($switch, 'switch')->create([
            'name' => 'ofis', 'vlan_tag' => 100, 'l3_interface' => 'vlan.100',
        ]);

        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->delete(route('switches.vlans.destroy', [$switch, $vlan]))
            ->assertRedirect();

        $this->assertStringContainsString(
            '<interface><name>vlan</name><unit delete="delete"><name>100</name></unit></interface>',
            $device->payload(),
        );
    }

    /**
     * L3 arayüzünde ADRES varsa VLAN silinemez.
     *
     * Arayüz birimi VLAN'la birlikte gidiyor ve üstündeki adres de onunla
     * birlikte silinirdi. Adres silmek ayrı bir karardır; VLAN silme onu
     * sessizce yapmamalı.
     */
    public function test_l3_arayuzunde_adres_varsa_vlan_silinemez(): void
    {
        $switch = $this->switch();
        $vlan = Vlan::factory()->for($switch, 'switch')->create([
            'name' => 'ofis', 'vlan_tag' => 100, 'l3_interface' => 'vlan.100',
        ]);

        SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'interface_name' => 'vlan.100',
            'address' => '10.9.9.1',
            'prefix_length' => 24,
        ]);

        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->delete(route('switches.vlans.destroy', [$switch, $vlan]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($device->connection);
        $this->assertNotNull($vlan->fresh());
    }

    /**
     * ae ÜYESİ porta VLAN ekranından üyelik verilemez.
     *
     * Port ekranı bunu zaten engelliyordu; VLAN ekranı engellemiyordu. Cihaz
     * "logical unit is not allowed on aggregated links" diyerek commit'i
     * düşürürdü.
     */
    public function test_ae_uyesi_porta_vlan_ekranindan_uyelik_verilemez(): void
    {
        $switch = $this->switch();
        $ae = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ae0']);
        $member = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'aggregate_port_id' => $ae->id,
        ]);

        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'kamera',
                'vlan_tag' => 30,
                'ports' => [$member->uuid],
            ])
            ->assertSessionHasErrors('ports');

        $this->assertNull($device->connection);
    }

    /**
     * ROUTED porta VLAN ekranından üyelik verilemez.
     *
     * Üyelik vermek inet ailesini kaldırmayı, yani portun IP yapılandırmasını
     * silmeyi gerektirir. Port ekranı bunu açık bir uyarıyla soruyor; VLAN
     * ekranında böyle bir seçim yok.
     */
    public function test_routed_porta_vlan_ekranindan_uyelik_verilemez(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/9',
            'port_mode' => PortMode::Routed,
        ]);

        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'kamera',
                'vlan_tag' => 30,
                'ports' => [$port->uuid],
            ])
            ->assertSessionHasErrors('ports');

        $this->assertNull($device->connection);
    }

    /**
     * ELS cihazda L3 arayüzü "vlan" değil "irb"dir; "interfaces vlan" diye
     * bir arayüz yoktur ve yanlış adla yazılan yükleme commit check'te düşer.
     */
    public function test_els_cihazda_l3_arayuzu_irb_altina_yazilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();

        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'kamera',
                'vlan_tag' => 30,
                'l3_interface' => 'irb.30',
            ])
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<interface><name>irb</name><unit><name>30</name></unit></interface>', $payload);
        $this->assertStringContainsString('<l3-interface>irb.30</l3-interface>', $payload);
        $this->assertStringNotContainsString('<name>vlan</name>', $payload);
    }

    /**
     * Cihazdan "irb.30" okunmuşsa form onu geri çevirmemeli: doğrulama
     * kullanıcının cihazındaki geçerli değeri reddederse o VLAN hiç
     * düzenlenemez hâle gelirdi.
     */
    public function test_irb_arayuzu_dogrulamadan_gecer(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();
        $vlan = Vlan::factory()->for($switch, 'switch')->create([
            'name' => 'ofis', 'vlan_tag' => 100, 'l3_interface' => 'irb.100',
        ]);

        $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->put(route('switches.vlans.update', [$switch, $vlan]), [
                'vlan_tag' => 100,
                'l3_interface' => 'irb.100',
                'description' => 'ofis katı',
            ])
            ->assertSessionHasNoErrors();
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
     * AD CİHAZDA VLAN'IN KİMLİĞİDİR.
     *
     * Yalnız etiketi tekil tutmak yetmez: var olan bir adla "VLAN ekle"
     * demek, merge yüzünden cihazdaki VLAN'ı yeniden etiketler,
     * açıklamasını ve l3-interface'ini siler ve bütün üye portlarını yeni
     * etikete taşır — panel ise "eklendi" der.
     */
    public function test_ayni_adla_ikinci_vlan_eklenemez(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ofis', 'vlan_tag' => 100]);

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'ofis',
                'vlan_tag' => 200,
            ])
            ->assertSessionHasErrors('name');

        $this->assertNull($device->connection);
    }

    /**
     * Başka switchte aynı ad serbesttir: kimlik switch başınadır.
     */
    public function test_baska_switchte_ayni_ad_serbest(): void
    {
        $switch = $this->switch();
        $other = NetworkSwitch::factory()->create();
        $this->fakeDevice();

        Vlan::factory()->for($other, 'switch')->create(['name' => 'ofis', 'vlan_tag' => 100]);

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.vlans.store', $switch), [
                'name' => 'ofis',
                'vlan_tag' => 100,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    private function fakeDevice(): FakeJunosDevice
    {
        $device = new FakeJunosDevice('[edit vlans]');
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
