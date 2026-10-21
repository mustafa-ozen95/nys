<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchIpAddress;
use App\Models\SwitchPort;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Adres yazma yolunun cihaza NE gönderdiği.
 *
 * İki kural burada kilitleniyor:
 *
 * 1. "Bu arayüzün son adresi mi" sorusunun cevabı CİHAZDADIR. Envanter
 *    sayımı bayattır; CLI'dan eklenmiş bir adres panelde görünmez ve aileyi
 *    silmek onu da süpürürdü.
 * 2. Adresin ailesi adresin KENDİSİNDEN gelir. "inet" sabit yazıldığında
 *    cihazdan okunan bir IPv6 adresi yanlış ailenin altına yazılıyordu.
 */
final class IpAddressWriteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cihazda İKİNCİ bir adres varken aile silinmemeli.
     *
     * Karar envanter sayımına dayanamaz: panelde tek kayıt varsa "son adres"
     * sanılır ve "family inet delete" yazılırsa, CLI'dan eklenmiş ve panelde
     * görünmeyen ikinci adresle birlikte arayüzdeki BÜTÜN inet adresleri tek
     * silmede gider.
     */
    public function test_cihazda_baska_adres_varsa_aile_silinmez(): void
    {
        $switch = $this->switch();
        $vlan = $this->vlanWithL3($switch);

        $address = SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'vlan_id' => $vlan->id,
            'interface_name' => 'vlan.100',
            'address' => '10.9.9.1',
            'prefix_length' => 24,
        ]);

        // Cihazda İKİ adres var; ikincisini panel hiç bilmiyor.
        $device = $this->deviceWith(
            '<interface><name>vlan</name><unit><name>100</name><family><inet>'
            .'<address><name>10.9.9.1/24</name></address>'
            .'<address><name>10.9.9.2/24</name></address>'
            .'</inet></family></unit></interface>'
        );

        $this->actingAs($this->operator())
            ->delete(route('switches.ip-addresses.destroy', [$switch, $address]))
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<address delete="delete"><name>10.9.9.1/24</name></address>', $payload);
        $this->assertStringNotContainsString('<inet delete="delete"/>', $payload);
    }

    /**
     * Gerçekten son adresse aile de kalkar; yoksa geride boş bir
     * "family inet;" kalır ve arayüz cihazın gözünde hâlâ routed olur.
     */
    public function test_son_adres_silinince_aile_de_kalkar(): void
    {
        $switch = $this->switch();
        $vlan = $this->vlanWithL3($switch);

        $address = SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'vlan_id' => $vlan->id,
            'interface_name' => 'vlan.100',
            'address' => '10.9.9.1',
            'prefix_length' => 24,
        ]);

        $device = $this->deviceWith(
            '<interface><name>vlan</name><unit><name>100</name><family><inet>'
            .'<address><name>10.9.9.1/24</name></address></inet></family></unit></interface>'
        );

        $this->actingAs($this->operator())
            ->delete(route('switches.ip-addresses.destroy', [$switch, $address]))
            ->assertRedirect();

        $this->assertStringContainsString('<inet delete="delete"/>', $device->payload());
    }

    /**
     * Ailenin altında adresten BAŞKA bir şey varsa aile silinmez.
     *
     * "family inet delete" yalnızca adresleri değil, o ailenin altındaki her
     * şeyi siler: giriş/çıkış filtresi, mtu, sampling. Panel bunları
     * okumuyor — okumadığı satırı silmesi, kullanıcının hiç haberi olmadan
     * arayüzün korumasını kaldırmak demekti.
     */
    public function test_aile_altinda_filtre_varsa_aile_silinmez(): void
    {
        $switch = $this->switch();
        $vlan = $this->vlanWithL3($switch);

        $address = SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'vlan_id' => $vlan->id,
            'interface_name' => 'vlan.100',
            'address' => '10.9.9.1',
            'prefix_length' => 24,
        ]);

        // Tek adres var ama ailenin altında bir de giriş filtresi duruyor.
        $device = $this->deviceWith(
            '<interface><name>vlan</name><unit><name>100</name><family><inet>'
            .'<filter><input><filter-name>koruma</filter-name></input></filter>'
            .'<address><name>10.9.9.1/24</name></address>'
            .'</inet></family></unit></interface>'
        );

        $this->actingAs($this->operator())
            ->delete(route('switches.ip-addresses.destroy', [$switch, $address]))
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<address delete="delete"><name>10.9.9.1/24</name></address>', $payload);
        $this->assertStringNotContainsString('<inet delete="delete"/>', $payload);
    }

    /**
     * Aile ADRESTEN çıkarılır. Aynı arayüzde bir v4 ve bir v6 adres
     * varken v6'yı silmek "inet" ailesini kaldırıyordu — istenmeyen adres.
     */
    public function test_ipv6_adres_silinince_inet6_ailesi_hedeflenir(): void
    {
        $switch = $this->switch();
        $vlan = $this->vlanWithL3($switch);

        $address = SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'vlan_id' => $vlan->id,
            'interface_name' => 'vlan.100',
            'address' => '2001:db8::1',
            'prefix_length' => 64,
        ]);

        $device = $this->deviceWith(
            '<interface><name>vlan</name><unit><name>100</name>'
            .'<family><inet><address><name>10.9.9.1/24</name></address></inet>'
            .'<inet6><address><name>2001:db8::1/64</name></address></inet6></family>'
            .'</unit></interface>'
        );

        $this->actingAs($this->operator())
            ->delete(route('switches.ip-addresses.destroy', [$switch, $address]))
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<inet6 delete="delete"/>', $payload);
        $this->assertStringNotContainsString('<inet delete="delete"/>', $payload);
    }

    /**
     * Cihazdan okunan bir IPv6 adresi başka arayüze taşınırken de
     * "inet6" altına yazılmalı. Sabit "inet" ile yükleme cihazda reddediliyordu.
     */
    public function test_ipv6_adres_inet6_altina_yazilir(): void
    {
        $switch = $this->switch();
        $vlan = $this->vlanWithL3($switch);
        $hedef = Vlan::factory()->for($switch, 'switch')->create([
            'name' => 'ikinci', 'vlan_tag' => 200, 'l3_interface' => 'vlan.200',
        ]);

        $address = SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'vlan_id' => $vlan->id,
            'interface_name' => 'vlan.100',
            'address' => '2001:db8::1',
            'prefix_length' => 64,
        ]);

        $device = $this->deviceWith(
            '<interface><name>vlan</name><unit><name>100</name><family><inet6>'
            .'<address><name>2001:db8::1/64</name></address></inet6></family></unit></interface>'
        );

        $this->actingAs($this->operator())
            ->put(route('switches.ip-addresses.update', [$switch, $address]), [
                'attachment' => 'vlan',
                'vlan_id' => $hedef->uuid,
                'address' => '2001:db8::1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<inet6><address><name>2001:db8::1/64</name></address></inet6>', $payload);
        $this->assertStringNotContainsString('<inet><address>', $payload);
    }

    /**
     * Adres BAŞKA arayüze taşınırken eskisinde aile de kalkar.
     *
     * Yalnızca adres silinirse geride boş bir "family inet;" kalır: o arayüz
     * cihazın gözünde hâlâ routed'dır ve iz her taşımada birikir.
     */
    public function test_adres_tasinirken_eski_arayuzde_aile_birakilmaz(): void
    {
        $switch = $this->switch();
        $vlan = $this->vlanWithL3($switch);
        $hedef = Vlan::factory()->for($switch, 'switch')->create([
            'name' => 'ikinci', 'vlan_tag' => 200, 'l3_interface' => 'vlan.200',
        ]);

        $address = SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'vlan_id' => $vlan->id,
            'interface_name' => 'vlan.100',
            'address' => '10.9.9.1',
            'prefix_length' => 24,
        ]);

        $device = $this->deviceWith(
            '<interface><name>vlan</name><unit><name>100</name><family><inet>'
            .'<address><name>10.9.9.1/24</name></address></inet></family></unit></interface>'
        );

        $this->actingAs($this->operator())
            ->put(route('switches.ip-addresses.update', [$switch, $address]), [
                'attachment' => 'vlan',
                'vlan_id' => $hedef->uuid,
                'address' => '10.9.9.1',
            ])
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<inet delete="delete"/>', $payload);
        $this->assertStringContainsString('<name>200</name>', $payload);
    }

    /**
     * AYNI arayüzde kalıyorsa aile silinmez — yeni adres zaten onun
     * altına yazılıyor. Silinseydi aynı yüklemede kaldırılıp geri konurdu.
     */
    public function test_ayni_arayuzde_adres_degisince_aile_silinmez(): void
    {
        $switch = $this->switch();
        $vlan = $this->vlanWithL3($switch);
        AddressBlock::factory()->network('10.9.9.0/24')->create([
            'core_switch_id' => $switch->id,
        ]);

        $address = SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'vlan_id' => $vlan->id,
            'interface_name' => 'vlan.100',
            'address' => '10.9.9.1',
            'prefix_length' => 24,
        ]);

        $device = $this->deviceWith(
            '<interface><name>vlan</name><unit><name>100</name><family><inet>'
            .'<address><name>10.9.9.1/24</name></address></inet></family></unit></interface>'
        );

        $this->actingAs($this->operator())
            ->put(route('switches.ip-addresses.update', [$switch, $address]), [
                'attachment' => 'vlan',
                'vlan_id' => $vlan->uuid,
                'address' => '10.9.9.5',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<address delete="delete"><name>10.9.9.1/24</name></address>', $payload);
        $this->assertStringNotContainsString('<inet delete="delete"/>', $payload);
        $this->assertStringContainsString('<name>10.9.9.5/24</name>', $payload);
    }

    /**
     * Cihazda hiç adres kalmayınca envanterde hayalet kayıt kalmamalı.
     *
     * "Liste boşsa dokunma" koruması bozuk yanıt içindi; ama arayüz döndüren
     * bir cihazın sıfır adres bildirmesi GERÇEK bir durumdur — son adres
     * silindiğinde olur.
     */
    public function test_cihazda_adres_kalmayinca_envanter_de_bosalir(): void
    {
        $switch = $this->switch();
        $vlan = $this->vlanWithL3($switch);

        $address = SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'vlan_id' => $vlan->id,
            'interface_name' => 'vlan.100',
            'address' => '10.9.9.1',
            'prefix_length' => 24,
        ]);

        // Cihaz arayüz döndürüyor ama üstünde adres yok.
        $device = $this->deviceWith('<interface><name>vlan</name><unit><name>100</name></unit></interface>');

        $this->actingAs($this->operator())
            ->delete(route('switches.ip-addresses.destroy', [$switch, $address]))
            ->assertRedirect();

        $this->assertSame(0, $switch->ipAddresses()->count());
        $this->assertNotNull($device->connection);
    }

    /**
     * Loopback'e adres verilebilmeli.
     *
     * lo0, BGP local-address ve router-id için tipik arayüzdür; panelden
     * adres verilemezse o iş CLI'da kalır. Formda karşılığı olduğu için
     * "korumalı" sayılmaz.
     */
    public function test_loopbacke_adres_verilebilir(): void
    {
        $switch = $this->switch();
        AddressBlock::factory()->network('10.9.9.0/24')->create(['core_switch_id' => $switch->id]);

        $device = $this->deviceWith('<interface><name>lo0</name><unit><name>0</name></unit></interface>');

        $this->actingAs($this->operator())
            ->post(route('switches.ip-addresses.store', $switch), [
                'attachment' => 'loopback',
                'address' => '10.9.9.7',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<interface><name>lo0</name><unit><name>0</name>', $payload);
        $this->assertStringContainsString('<name>10.9.9.7/24</name>', $payload);

        // lo0'da ethernet-switching ailesi YOKTUR; silme isteği gitmemeli.
        $this->assertStringNotContainsString('ethernet-switching', $payload);
    }

    /**
     * Loopback'in family inet'i altında genelde RE koruma filtresi durur —
     * test1 üzerinde ölçüldü: lo0.0 yalnızca "filter input RE_FILTER"
     * taşıyor. Son adresi silerken aileyi kaldırmak o filtreyi de silerdi:
     * yönlendirme motorunun koruması, kullanıcının hiç haberi olmadan
     * kalkardı.
     */
    public function test_loopback_adresi_silinince_re_filtresi_kalir(): void
    {
        $switch = $this->switch();

        $address = SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'vlan_id' => null,
            'switch_port_id' => null,
            'interface_name' => 'lo0.0',
            'address' => '10.9.9.7',
            'prefix_length' => 32,
        ]);

        $device = $this->deviceWith(
            '<interface><name>lo0</name><unit><name>0</name><family><inet>'
            .'<filter><input><filter-name>RE_FILTER</filter-name></input></filter>'
            .'<address><name>10.9.9.7/32</name></address>'
            .'</inet></family></unit></interface>'
        );

        $this->actingAs($this->operator())
            ->delete(route('switches.ip-addresses.destroy', [$switch, $address]))
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<address delete="delete"><name>10.9.9.7/32</name></address>', $payload);
        $this->assertStringNotContainsString('<inet delete="delete"/>', $payload);
    }

    /**
     * ae ÜYESİ porta adres verilemez.
     *
     * Adres portun 0 numaralı birimine yazılır ve cihaz "logical unit is not
     * allowed on aggregated links" ile commit'i düşürür. Port ekranı ve VLAN
     * ekranı bunu engelliyordu, adres ekranı engellemiyordu — aynı cihaz
     * satırına giden üçüncü yol.
     */
    public function test_ae_uyesi_porta_adres_verilemez(): void
    {
        $switch = $this->switch();
        AddressBlock::factory()->network('10.9.9.0/24')->create(['core_switch_id' => $switch->id]);

        $ae = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ae0']);
        $uye = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'aggregate_port_id' => $ae->id,
        ]);

        $device = $this->deviceWith('');

        $this->actingAs($this->operator())
            ->post(route('switches.ip-addresses.store', $switch), [
                'attachment' => 'port',
                'switch_port_id' => $uye->uuid,
                'address' => '10.9.9.7',
            ])
            ->assertSessionHasErrors('switch_port_id');

        $this->assertNull($device->connection);
    }

    /**
     * ae ARAYÜZÜNÜN KENDİSİNE adres verilebilir: routed LAG olağan bir
     * yapılandırmadır ve koruma onu engellememeli.
     */
    public function test_ae_arayuzune_adres_verilebilir(): void
    {
        $switch = $this->switch();
        AddressBlock::factory()->network('10.9.9.0/24')->create(['core_switch_id' => $switch->id]);

        $ae = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ae0']);
        $device = $this->deviceWith('');

        $this->actingAs($this->operator())
            ->post(route('switches.ip-addresses.store', $switch), [
                'attachment' => 'port',
                'switch_port_id' => $ae->uuid,
                'address' => '10.9.9.7',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertStringContainsString('<name>ae0</name>', $device->payload());
    }

    /**
     * IPv6 adres panelden EKLENEBİLİR.
     *
     * Aralık paketlenmiş bayt olarak tutulur; adresleme IPv4'e kilitli olsaydı
     * (ip2long, max:32) IPv6 bloğu tanımlanamaz, maske kaynağı bulunamaz ve
     * adres hiç verilemezdi.
     */
    public function test_ipv6_adres_eklenebilir(): void
    {
        $switch = $this->switch();
        $vlan = $this->vlanWithL3($switch);

        AddressBlock::factory()->network('2001:db8:5::/64')->create([
            'core_switch_id' => $switch->id,
        ]);

        $device = $this->deviceWith('<interface><name>vlan</name><unit><name>100</name></unit></interface>');

        $this->actingAs($this->operator())
            ->post(route('switches.ip-addresses.store', $switch), [
                'attachment' => 'vlan',
                'vlan_id' => $vlan->uuid,
                'address' => '2001:db8:5::1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<inet6><address><name>2001:db8:5::1/64</name></address></inet6>', $payload);
    }

    private function deviceWith(string $interfaceExtras): FakeJunosDevice
    {
        $device = new FakeJunosDevice(
            interfaces: [],
            interfaceExtras: $interfaceExtras,
        );

        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function vlanWithL3(NetworkSwitch $switch): Vlan
    {
        return Vlan::factory()->for($switch, 'switch')->create([
            'name' => 'ofis',
            'vlan_tag' => 100,
            'l3_interface' => 'vlan.100',
        ]);
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

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource(
            $user,
            'switches',
            ['view', 'create', 'update', 'configure'],
        );
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }
}
