<?php

declare(strict_types=1);

namespace Tests\Feature\Bgp;

use App\Enums\CredentialProtocol;
use App\Models\BgpGroup;
use App\Models\BgpNeighbor;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Komşudan alınan prefixler.
 *
 * Yanıt, iki lab cihazı (jn32-test, jn33-test) arasındaki bir anonsun
 * çıktısı biçimindedir: iki blok duyurulur, biri kabul edilir biri reddedilir.
 */
final class ReceivedRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_kabul_edilen_ve_reddedilen_ayri_doner(): void
    {
        [$switch, $neighbor] = $this->neighbor();
        $this->device();

        $this->actingAs($this->operator())
            ->getJson(route('ajax.bgp.received', [$switch, $neighbor]))
            ->assertOk()
            ->assertJsonPath('neighbor', '198.18.8.37')
            ->assertJsonPath('accepted.0.prefix', '192.168.50.0/24')
            ->assertJsonPath('accepted.0.as_path', '65001 I')
            ->assertJsonPath('rejected.0.prefix', '192.168.51.0/24');
    }

    public function test_yetkisiz_kullanici_okuyamaz(): void
    {
        [$switch, $neighbor] = $this->neighbor();
        $device = $this->device();

        $this->actingAs(User::factory()->create())
            ->getJson(route('ajax.bgp.received', [$switch, $neighbor]))
            ->assertForbidden();

        // Yetki reddedildiyse cihaza hiç bağlanılmamalı.
        $this->assertNull($device->connection);
    }

    /**
     * İç içe rotada alt kayıt üst kayda ait olmalı.
     */
    public function test_baska_switchin_komsusu_okunamaz(): void
    {
        [$switch] = $this->neighbor();
        [, $foreign] = $this->neighbor('198.18.20.196', '198.18.8.41');
        $device = $this->device();

        $this->actingAs($this->operator())
            ->getJson(route('ajax.bgp.received', [$switch, $foreign]))
            ->assertNotFound();

        $this->assertNull($device->connection);
    }

    /**
     * @return array{0: NetworkSwitch, 1: BgpNeighbor}
     */
    private function neighbor(string $ip = '198.18.20.195', string $address = '198.18.8.37'): array
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => $ip]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        $group = BgpGroup::factory()->for($switch, 'switch')->create();
        $neighbor = BgpNeighbor::factory()->for($group, 'group')->for($switch, 'switch')
            ->create(['address' => $address]);

        return [$switch->refresh(), $neighbor];
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice(
            routeInformation: file_get_contents(__DIR__.'/../../Fixtures/netconf/received-routes.xml')."\n]]>]]>",
        );

        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        return $user->fresh();
    }
}
