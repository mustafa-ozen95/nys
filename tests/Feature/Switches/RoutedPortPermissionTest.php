<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\FirewallFamily;
use App\Enums\PortMode;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchFirewallFilter;
use App\Models\SwitchIpAddress;
use App\Models\SwitchPort;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Port formu yönlendirme modunda IP adresi ve firewall filtresi bağlaması da
 * yazar; bunların kendi yetkisi var (switch_ip_addresses, switch_firewall).
 * Yalnızca switch_ports yetkisi olan biri ikisini de değiştirebiliyordu.
 */
final class RoutedPortPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_port_yetkisi_filtre_baglayamaz(): void
    {
        [$switch, $port] = $this->kurulum();
        $device = $this->device();

        $this->actingAs($this->portUser())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'routed',
                'input_filter' => 'koruma',
            ])
            ->assertSessionHasErrors('input_filter');

        $this->assertNull($device->connection);
    }

    public function test_port_yetkisi_ip_adresi_veremez(): void
    {
        [$switch, $port] = $this->kurulum();
        $device = $this->device();

        $this->actingAs($this->portUser())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'routed',
                'ip_address' => '10.9.9.7',
            ])
            ->assertSessionHasErrors('ip_address');

        $this->assertNull($device->connection);
    }

    /**
     * Form mevcut adres ve filtreyi olduğu gibi geri gönderir; açıklamayı
     * değiştiren port yetkilisi yetki hatası ALMAMALI.
     */
    public function test_degismeyen_adres_ve_filtre_yetki_istemez(): void
    {
        [$switch, $port] = $this->kurulum();
        SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'switch_port_id' => $port->id,
            'interface_name' => 'ge-0/0/3.0',
            'address' => '10.9.9.7',
            'prefix_length' => 24,
        ]);
        SwitchFirewallFilter::query()->where('name', 'koruma')->update([
            'bindings' => [['interface' => 'ge-0/0/3.0', 'direction' => 'input']],
        ]);
        $this->device();

        $this->actingAs($this->portUser())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'routed',
                'description' => 'yeni',
                'ip_address' => '10.9.9.7',
                'input_filter' => 'koruma',
                'output_filter' => '',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_firewall_ve_adres_yetkisi_olan_yapabilir(): void
    {
        [$switch, $port] = $this->kurulum();
        $this->device();

        $user = $this->portUser();
        $resolver = app(UserPermissionResolver::class);
        $resolver->syncResource($user, 'switch_firewall', ['view', 'update']);
        $resolver->syncResource($user, 'switch_ip_addresses', ['view', 'update']);

        $this->actingAs($user->fresh())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => 'routed',
                'input_filter' => 'koruma',
                'ip_address' => '10.9.9.7',
            ])
            ->assertSessionHasNoErrors();
    }

    private function portUser(): User
    {
        $user = User::factory()->create();
        $resolver = app(UserPermissionResolver::class);
        $resolver->syncResource($user, 'switches', ['view']);
        $resolver->syncResource($user, 'switch_ports', ['view', 'update']);

        return $user->fresh();
    }

    /**
     * @return array{0: NetworkSwitch, 1: SwitchPort}
     */
    private function kurulum(): array
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.195']);
        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);
        $switch->refresh();

        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/3', 'port_mode' => PortMode::Routed]);

        SwitchFirewallFilter::factory()->for($switch, 'switch')
            ->create(['name' => 'koruma', 'family' => FirewallFamily::Inet]);

        AddressBlock::factory()->network('10.9.9.0/24')->create(['core_switch_id' => $switch->id]);

        return [$switch, $port];
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
