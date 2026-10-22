<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Models\BgpGroup;
use App\Models\BgpNeighbor;
use App\Models\NetworkSwitch;
use App\Models\SwitchPolicyStatement;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * BGP ekranı cihaza yazar.
 *
 * Beklenen XML tahmin değil: her biçim jn32-test üzerinde denendi ve cihazın
 * kanonik çıktısıyla karşılaştırıldı (tests/Fixtures/netconf/bgp-*.xml).
 */
final class BgpWriteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Panelden devre dışı bırakma ve geri açma.
     *
     * Junos nesneyi silmeden kapatabilir ve işareti ÖZNİTELİKTE taşır;
     * panel bunu hem göstermeli hem değiştirebilmeli. active="active" ile
     * geri açmanın kabul edildiği test1 üzerinde ölçüldü.
     */
    /**
     * Panelden devre dışı bırakma.
     *
     * Junos nesneyi silmeden kapatabilir ve işareti ÖZNİTELİKTE taşır;
     * panel bunu hem göstermeli hem değiştirebilmeli.
     */
    public function test_grup_devre_disi_birakilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'router',
                'type' => 'internal',
                'is_inactive' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertStringContainsString('<group inactive="inactive">', $device->payload());
    }

    /**
     * Geri açma: active="active". Cihazın bunu kabul ettiği test1 üzerinde
     * ölçüldü.
     */
    public function test_devre_disi_grup_geri_acilir(): void
    {
        $switch = $this->switch();
        $group = BgpGroup::factory()->for($switch, 'switch')
            ->create(['name' => 'router', 'is_inactive' => true]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.bgp.groups.update', [$switch, $group]), [
                'name' => 'router',
                'type' => 'external',
                'peer_asn' => '65001',
                'is_inactive' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<group active="active">', $device->payload());
    }

    /**
     * Alan HİÇ gönderilmezse cihazdaki durum korunur.
     *
     * boolean() eksik girdiyi false'a çevirir; alanı taşımayan bir istek
     * devre dışı duran bir grubu sessizce AÇARDI. Öznitelik yazılmayınca
     * merge cihazdaki işarete dokunmaz.
     */
    public function test_alan_gonderilmezse_devre_disi_durumu_korunur(): void
    {
        $switch = $this->switch();
        $group = BgpGroup::factory()->for($switch, 'switch')
            ->create(['name' => 'router', 'is_inactive' => true]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.bgp.groups.update', [$switch, $group]), [
                'name' => 'router',
                'type' => 'external',
                'peer_asn' => '65001',
                'description' => 'aciklama',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringNotContainsString('active="active"', $written);
        $this->assertStringNotContainsString('inactive="inactive"', $written);
    }

    public function test_grup_butun_ayarlariyla_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'musteri1',
                'type' => 'external',
                'peer_asn' => '64497',
                'local_address' => '198.51.100.133',
                'local_asn' => '65009',
                'multihop' => '1',
                'multihop_ttl' => '5',
                'log_updown' => '1',
                'passive' => '1',
                'description' => 'uplink',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $written = $device->payload();

        $this->assertStringContainsString('<protocols><bgp>', $written);
        $this->assertStringContainsString('<name>musteri1</name>', $written);
        $this->assertStringContainsString('<type>external</type>', $written);
        $this->assertStringContainsString('<peer-as>64497</peer-as>', $written);
        $this->assertStringContainsString('<multihop><ttl>5</ttl></multihop>', $written);
        $this->assertStringContainsString('<log-updown/>', $written);
        $this->assertStringContainsString('<passive/>', $written);
        // local-as düz değer değil, alt elemanlı düğümdür.
        $this->assertStringContainsString('<local-as><as-number>65009</as-number></local-as>', $written);
    }

    /**
     * TTL'siz multihop BOŞ eleman olarak yazılır; Junos onu böyle tutuyor.
     */
    public function test_ttlsiz_multihop_bos_eleman_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'komsu', 'type' => 'external', 'peer_asn' => '65001', 'multihop' => '1',
            ])
            ->assertRedirect();

        $this->assertStringContainsString('<multihop/>', $device->payload());
    }

    /**
     * Omurgalarda yaygın hâl: sınır aşılınca düşür, süresiz kapalı kal.
     * "forever" BOŞ bir idle-timeout elemanıdır.
     */
    public function test_prefix_siniri_ve_suresiz_bekleme_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'komsu', 'type' => 'external', 'peer_asn' => '65001',
                'families' => [
                    'inet' => [
                        'enabled' => '1', 'nlri' => 'any', 'prefix_limit_maximum' => '200',
                        'teardown' => '1', 'teardown_threshold' => '100', 'idle_timeout_forever' => '1',
                    ],
                ],
            ])
            ->assertRedirect();

        $written = $device->payload();

        $this->assertStringContainsString('<family><inet><any><prefix-limit>', $written);
        $this->assertStringContainsString('<maximum>200</maximum>', $written);
        $this->assertStringContainsString('<limit-threshold>100</limit-threshold>', $written);
        $this->assertStringContainsString('<idle-timeout/>', $written);
    }

    public function test_dakikali_bekleme_alt_elemanla_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'komsu', 'type' => 'external', 'peer_asn' => '65001',
                'families' => [
                    'inet' => [
                        'enabled' => '1', 'nlri' => 'unicast', 'prefix_limit_maximum' => '100',
                        'teardown' => '1', 'idle_timeout_minutes' => '30',
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertStringContainsString('<idle-timeout><timeout>30</timeout></idle-timeout>', $device->payload());
    }

    /**
     * Junos merge yalnızca EKLER. Bir alanı boşaltmak için önce silinmeli;
     * aileler ise TEK TEK silinmeli — kapsayıcıyı silmek cihazda sessizce
     * hiçbir şey yapmıyor (gerçek cihazda görüldü).
     */
    public function test_guncelleme_once_siler_sonra_yazar(): void
    {
        $switch = $this->switch();
        $group = BgpGroup::factory()->for($switch, 'switch')->create(['name' => 'musteri1']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.bgp.groups.update', [$switch, $group]), [
                'name' => 'musteri1', 'type' => 'external', 'peer_asn' => '65001',
            ])
            ->assertRedirect();

        $written = $device->payload();

        $this->assertStringContainsString('<multihop delete="delete"/>', $written);
        $this->assertStringContainsString('<local-address delete="delete"/>', $written);
        $this->assertStringContainsString('<import delete="delete"/>', $written);
        $this->assertStringContainsString('<inet delete="delete"/>', $written);
        $this->assertStringContainsString('<inet6 delete="delete"/>', $written);

        // Grubun KENDİSİ silinmez: altındaki komşular korunmalı.
        $this->assertStringNotContainsString('<group delete="delete">', $written);
    }

    /**
     * Cihaz AS numarası tanımlı değilken BGP'yi commit etmiyor:
     * "must define local autonomous system when enabling BGP".
     */
    public function test_as_numarasi_yokken_grup_eklenemez(): void
    {
        $switch = $this->switch(autonomousSystem: null);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'komsu', 'type' => 'external', 'peer_asn' => '65001',
            ])
            ->assertSessionHasErrors('name');

        $this->assertNull($device->connection);
    }

    /**
     * Tanımsız politikaya atıf cihazda reddediliyor:
     * "Policy error: Policy X referenced but not defined".
     */
    public function test_cihazda_olmayan_politika_secilemez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'komsu', 'type' => 'external', 'peer_asn' => '65001',
                'import_policies' => ['olmayan-politika'],
            ])
            ->assertSessionHasErrors('import_policies.0');

        $this->assertNull($device->connection);
    }

    public function test_cihazda_tanimli_politika_secilebilir(): void
    {
        $switch = $this->switch();
        SwitchPolicyStatement::factory()->for($switch, 'switch')->create(['name' => 'prefixes-from-musteri1']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'komsu', 'type' => 'external', 'peer_asn' => '65001',
                'import_policies' => ['prefixes-from-musteri1'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<import>prefixes-from-musteri1</import>', $device->payload());
    }

    public function test_komsu_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $group = BgpGroup::factory()->for($switch, 'switch')->create(['name' => 'musteri1']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.neighbors.store', [$switch, $group]), [
                'address' => '198.51.100.134',
                'description' => 'uplink komsusu',
                'peer_asn' => '64497',
            ])
            ->assertRedirect();

        $written = $device->payload();

        $this->assertStringContainsString('<neighbor><name>198.51.100.134</name>', $written);
        $this->assertStringContainsString('<description>uplink komsusu</description>', $written);
    }

    /**
     * Komşu adresi cihazda switch genelinde benzersizdir; aynı adres iki
     * grupta duramaz.
     */
    public function test_ayni_komsu_adresi_iki_kez_eklenemez(): void
    {
        $switch = $this->switch();
        $group = BgpGroup::factory()->for($switch, 'switch')->create();
        BgpNeighbor::factory()->for($group, 'group')->for($switch, 'switch')
            ->create(['address' => '198.51.100.134']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.neighbors.store', [$switch, $group]), ['address' => '198.51.100.134'])
            ->assertSessionHasErrors('address');

        $this->assertNull($device->connection);
    }

    /**
     * İç içe rotada alt kayıt üst kayda ait olmalı; olmazsa A switchinde
     * yetkisi olan biri B switchinin komşusunu değiştirebilirdi.
     */
    public function test_baska_switchin_grubu_uzerinden_islem_yapilamaz(): void
    {
        $switch = $this->switch();
        $otherSwitch = $this->switch('198.18.20.196');
        $foreignGroup = BgpGroup::factory()->for($otherSwitch, 'switch')->create();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.neighbors.store', [$switch, $foreignGroup]), ['address' => '198.51.100.134'])
            ->assertNotFound();

        $this->assertNull($device->connection);
    }

    public function test_as_numarasi_cihaza_yazilir(): void
    {
        $switch = $this->switch(autonomousSystem: null);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.bgp.autonomous-system', $switch), ['autonomous_system' => '65000'])
            ->assertRedirect();

        $this->assertStringContainsString(
            '<autonomous-system><as-number>65000</as-number></autonomous-system>',
            $device->payload(),
        );
    }

    private function switch(string $ip = '198.18.20.195', ?string $autonomousSystem = '65000'): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'management_ip' => $ip,
            'autonomous_system' => $autonomousSystem,
        ]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    private function operator(): User
    {
        $user = User::factory()->create();

        // BGP yazma ayrı bir yetkidir: "configure" onu kapsamaz.
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
