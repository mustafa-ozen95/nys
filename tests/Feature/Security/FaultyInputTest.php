<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\CredentialProtocol;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * BİLEREK HATALI GİRDİ.
 *
 * Mutlu yolu sınamak kolaydır; asıl soru panelin saçma, kötü niyetli ya da
 * yalnızca yanlış bir girdiyle ne yaptığıdır. İki şey birden beklenir:
 *
 *   1. İstek DOĞRULAMADA reddedilir (500 değil, sessiz kabul değil).
 *   2. CİHAZA HİÇBİR ŞEY GİTMEZ. Bu ikincisi kritiktir: doğrulamayı geçen
 *      bir çöp, cihazda yarım bir yapılandırma bırakabilir.
 *
 * Cihaza gidilmediği $device->connection === null ile kanıtlanır: sahte
 * taşıma hiç açılmamış olmalı.
 */
final class FaultyInputTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function portGirdileri(): array
    {
        return [
            'olmayan mod' => [['port_mode' => 'kirlangic'], 'port_mode'],
            'mod hiç yok' => [[], 'port_mode'],
            'mtu harf' => [['port_mode' => 'access', 'mtu' => 'çokbüyük'], 'mtu'],
            'mtu negatif' => [['port_mode' => 'access', 'mtu' => -1], 'mtu'],
            'mtu aşırı' => [['port_mode' => 'access', 'mtu' => 999999], 'mtu'],
            'hız uydurma' => [['port_mode' => 'access', 'speed_config' => '42'], 'speed_config'],
            'duplex uydurma' => [['port_mode' => 'access', 'duplex_config' => 'ucdubleks'], 'duplex_config'],
            // Laravel dizgeyi tek elemanlı diziye çevirir; hata elemanda bildirilir.
            'vlan dizi değil' => [['port_mode' => 'access', 'vlans' => 'hepsi'], 'vlans.0'],
            'vlan uuid değil' => [['port_mode' => 'access', 'vlans' => ['../../etc/passwd']], 'vlans.0'],
            'açıklama aşırı uzun' => [['port_mode' => 'access', 'description' => str_repeat('a', 5000)], 'description'],
        ];
    }

    #[DataProvider('portGirdileri')]
    public function test_port_ekrani_hatali_girdiyi_cihaza_gondermez(array $girdi, string $alan): void
    {
        [$switch, $port, $device] = $this->hazirla();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), $girdi)
            ->assertSessionHasErrors($alan);

        $this->assertNull($device->connection, 'Doğrulama düşmesine rağmen cihaza bağlanıldı.');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function vlanGirdileri(): array
    {
        return [
            'ad yok' => [['vlan_tag' => 30], 'name'],
            'etiket aralık dışı' => [['name' => 'ofis', 'vlan_tag' => 99999], 'vlan_tag'],
            'etiket sıfır' => [['name' => 'ofis', 'vlan_tag' => 0], 'vlan_tag'],
            'etiket harf' => [['name' => 'ofis', 'vlan_tag' => 'otuz'], 'vlan_tag'],
            'l3 arayüz uydurma' => [['name' => 'ofis', 'vlan_tag' => 30, 'l3_interface' => 'kirlangic.5'], 'l3_interface'],
            'l3 arayüz enjeksiyon' => [['name' => 'ofis', 'vlan_tag' => 30, 'l3_interface' => 'vlan.5; delete all'], 'l3_interface'],
            'ad aşırı uzun' => [['name' => str_repeat('v', 300), 'vlan_tag' => 30], 'name'],
        ];
    }

    #[DataProvider('vlanGirdileri')]
    public function test_vlan_ekrani_hatali_girdiyi_cihaza_gondermez(array $girdi, string $alan): void
    {
        [$switch, , $device] = $this->hazirla();

        $this->actingAs($this->operator())
            ->post(route('switches.vlans.store', $switch), $girdi)
            ->assertSessionHasErrors($alan);

        $this->assertNull($device->connection, 'Doğrulama düşmesine rağmen cihaza bağlanıldı.');
    }

    /**
     * Başka bir switchin VLAN'ı bu switchin portuna verilemez.
     *
     * Doğrulama yalnızca "uuid geçerli mi" diye baksaydı, komşu switchin
     * VLAN adı bu cihaza yazılır ve cihazda olmayan bir VLAN'a üyelik
     * kurulurdu.
     */
    public function test_baska_switchin_vlani_kabul_edilmez(): void
    {
        [$switch, $port, $device] = $this->hazirla();
        $yabanci = Vlan::factory()->for(NetworkSwitch::factory()->create(), 'switch')->create();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'access',
                'vlans' => [$yabanci->uuid],
            ])
            ->assertSessionHasErrors();

        $this->assertNull($device->connection);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function routeGirdileri(): array
    {
        return [
            'hedef IP değil' => [['cidr' => 'burasi-bir-ip-degil', 'action' => 'next-hop', 'next_hops' => ['10.0.0.1']], 'cidr'],
            'maske aşırı' => [['cidr' => '10.0.0.0/99', 'action' => 'next-hop', 'next_hops' => ['10.0.0.1']], 'cidr'],
            'sonraki atlama saçma' => [['cidr' => '10.0.0.0/24', 'action' => 'next-hop', 'next_hops' => ['kirlangic']], 'next_hops.0'],
            'hedef boş' => [['action' => 'next-hop', 'next_hops' => ['10.0.0.1']], 'cidr'],
            'eylem uydurma' => [['cidr' => '10.0.0.0/24', 'action' => 'yut'], 'action'],
            'komut enjeksiyonu' => [['cidr' => '10.0.0.0/24; delete all', 'action' => 'discard'], 'cidr'],
            'metric aşırı' => [['cidr' => '10.0.0.0/24', 'action' => 'discard', 'metric' => 99999999999], 'metric'],
        ];
    }

    #[DataProvider('routeGirdileri')]
    public function test_route_ekrani_hatali_girdiyi_cihaza_gondermez(array $girdi, string $alan): void
    {
        [$switch, , $device] = $this->hazirla();

        $this->actingAs($this->operator())
            ->post(route('switches.routes.store', $switch), $girdi)
            ->assertSessionHasErrors($alan);

        $this->assertNull($device->connection, 'Doğrulama düşmesine rağmen cihaza bağlanıldı.');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function prefixListGirdileri(): array
    {
        return [
            'ad boş' => [[], 'name'],
            'ad aşırı uzun' => [['name' => str_repeat('p', 300)], 'name'],
        ];
    }

    #[DataProvider('prefixListGirdileri')]
    public function test_prefix_list_hatali_girdiyi_cihaza_gondermez(array $girdi, string $alan): void
    {
        [$switch, , $device] = $this->hazirla();

        $this->actingAs($this->operator())
            ->post(route('switches.prefix-lists.store', $switch), $girdi)
            ->assertSessionHasErrors($alan);

        $this->assertNull($device->connection, 'Doğrulama düşmesine rağmen cihaza bağlanıldı.');
    }

    /**
     * @return array{0: NetworkSwitch, 1: SwitchPort, 2: FakeJunosDevice}
     */
    private function hazirla(): array
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/5']);

        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return [$switch->refresh(), $port, $device];
    }

    private function operator(): User
    {
        $user = User::factory()->create();

        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_ports', ['view', 'update']);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_vlans', ['view', 'create', 'update', 'delete']);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_routes', ['view', 'create', 'update', 'delete']);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_prefix_lists', ['view', 'create', 'update', 'delete']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }
}
