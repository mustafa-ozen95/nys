<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\PortMode;
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
 * Port yazma yolunun cihazın reddedeceği isteklere karşı korumaları.
 *
 * Korumasız panel bu istekleri cihaza götürür ve kullanıcıya ham cihaz
 * hatası gösterir, ya da sessizce yanlış iş yapar.
 */
final class PortGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ae ÜYESİ portun kendi birimi olamaz.
     *
     * Cihaz "logical unit is not allowed on aggregated links" diyor; panel
     * port_mode'u her istekte gönderdiği için üye portun modalını kaydetmek
     * commit'i düşürüyordu.
     */
    public function test_ae_uyesine_anahtarlama_ayari_yazilamaz(): void
    {
        [$switch, $device] = $this->switchWithDevice();

        $ae = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ae0']);
        $member = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/20',
            'aggregate_port_id' => $ae->id,
        ]);

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $member]), [
                'port_mode' => PortMode::Access->value,
                'enabled' => '1',
            ])
            ->assertSessionHasErrors('port_mode');

        $this->assertNull($device->connection);
    }

    public function test_ae_uyesine_vlan_uyeligi_yazilamaz(): void
    {
        [$switch, $device] = $this->switchWithDevice();

        $ae = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ae0']);
        $member = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/21',
            'aggregate_port_id' => $ae->id,
        ]);

        $vlan = Vlan::factory()->for($switch, 'switch')->create();

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $member]), [
                'vlans' => [$vlan->uuid],
                'enabled' => '1',
            ])
            ->assertSessionHasErrors('vlans');

        $this->assertNull($device->connection);
    }

    /**
     * ADRESSİZ ama ROUTED port da anahtarlamaya döndürülebilmeli.
     *
     * CLI'dan son adres silinip geride boş "family inet;" kalmış port
     * envanterde adressiz ama hâlâ routed görünür. Karar yalnız adres
     * kaydına bakarsa "<inet delete>" yazılmaz ve cihaz "no other family
     * type can be configured" ile reddeder; port panelden bir daha
     * döndürülemez.
     */
    public function test_adressiz_routed_port_anahtarlamaya_dondurulebilir(): void
    {
        [$switch, $device] = $this->switchWithDevice();

        $port = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/9',
            'port_mode' => PortMode::Routed,
        ]);

        $this->assertSame(0, $port->ipAddresses()->count());

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Access->value,
                'enabled' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<inet delete="delete"/>', $device->payload());
    }

    /**
     * Adresi OLAN routed portta davranış değişmemeli.
     */
    public function test_adresli_routed_portta_inet_yine_silinir(): void
    {
        [$switch, $device] = $this->switchWithDevice();

        $port = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/10',
            'port_mode' => PortMode::Routed,
        ]);

        SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'switch_port_id' => $port->id,
            'interface_name' => 'ge-0/0/10',
        ]);

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Trunk->value,
                'enabled' => '1',
            ])
            ->assertRedirect();

        $this->assertStringContainsString('<inet delete="delete"/>', $device->payload());
    }

    /**
     * Anahtarlama modundaki portta inet silme belgesi GÖNDERİLMEZ: cihazda
     * olmayan bir düğümü silmeye çalışmanın anlamı yok.
     */
    public function test_access_portta_inet_silinmez(): void
    {
        [$switch, $device] = $this->switchWithDevice();

        $port = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/11',
            'port_mode' => PortMode::Access,
        ]);

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Trunk->value,
                'enabled' => '1',
            ])
            ->assertRedirect();

        $this->assertStringNotContainsString('<inet delete="delete"/>', $device->payload());
    }

    /**
     * @return array{0: NetworkSwitch, 1: FakeJunosDevice}
     */
    private function switchWithDevice(): array
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return [$switch->refresh(), $device];
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switch_ports', ['view', 'update']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }
}
