<?php

declare(strict_types=1);

namespace Tests\Feature\Addressing;

use App\Enums\AddressConnectionType;
use App\Enums\AddressPurpose;
use App\Enums\CredentialProtocol;
use App\Enums\SwitchRole;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Adreslemede route / geçit adresi ataması cihaza yazar; cihaz nesnesinin
 * kendi yetkisi (switch_routes, switch_ip_addresses) aranır.
 *
 * Yalnızca adresleme yetkisi olan biri, atama yöntemi üzerinden herhangi bir
 * switche route ve adres yazabiliyordu.
 */
final class AssignmentPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_yalnizca_adresleme_yetkisi_route_yazamaz(): void
    {
        $switch = $this->switch();
        $device = $this->device();
        $block = $this->block();

        $this->actingAs($this->ipamUser())
            ->put(route('addressing.update', $block), $this->routePayload($switch))
            ->assertSessionHasErrors('connection_type');

        $this->assertNull($device->connection, 'Reddedilen atama cihaza gitmemeliydi.');
        $this->assertNull($block->fresh()->connection_type);
    }

    public function test_route_ekleme_yetkisi_olan_yazabilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();
        $block = $this->block();

        $user = $this->ipamUser();
        $resolver = app(UserPermissionResolver::class);
        $resolver->syncResource($user, 'switches', ['view']);
        $resolver->syncResource($user, 'switch_routes', ['view', 'create']);

        $this->actingAs($user->fresh())
            ->put(route('addressing.update', $block), $this->routePayload($switch))
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<next-hop>10.0.0.2</next-hop>', implode('', $device->written));
    }

    public function test_yalnizca_adresleme_yetkisi_gecit_adresi_yazamaz(): void
    {
        $switch = $this->switch();
        SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/1']);
        $device = $this->device();
        $block = $this->block();

        $this->actingAs($this->ipamUser())
            ->put(route('addressing.update', $block), [
                'purpose' => AddressPurpose::Customer->value,
                'connection_type' => AddressConnectionType::Port->value,
                'core_switch_id' => $switch->uuid,
                'port_name' => 'ge-0/0/1',
            ])
            ->assertSessionHasErrors('connection_type');

        $this->assertNull($device->connection);
    }

    /**
     * Atama DEĞİŞMİYORSA cihaz yetkisi aranmaz: açıklaması düzenlenen bloğun
     * kaydı eskisi gibi çalışır.
     */
    public function test_atama_ayniysa_cihaz_yetkisi_aranmaz(): void
    {
        $switch = $this->switch();
        $device = $this->device();
        $block = $this->block([
            'connection_type' => AddressConnectionType::NextHop,
            'core_switch_id' => $switch->id,
            'next_hop' => '10.0.0.2',
        ]);

        $this->actingAs($this->ipamUser())
            ->put(route('addressing.update', $block), $this->routePayload($switch) + ['description' => 'yeni başlık'])
            ->assertSessionHasNoErrors();

        $this->assertSame('yeni başlık', $block->fresh()->description);
        $this->assertNull($device->connection);
    }

    public function test_atamali_blok_silmek_cihaz_silme_yetkisi_ister(): void
    {
        $switch = $this->switch();
        $device = $this->device();
        $block = $this->block([
            'connection_type' => AddressConnectionType::NextHop,
            'core_switch_id' => $switch->id,
            'next_hop' => '10.0.0.2',
        ]);

        $this->actingAs($this->ipamUser())
            ->delete(route('addressing.destroy', $block))
            ->assertForbidden();

        $this->assertNotNull($block->fresh());
        $this->assertNull($device->connection);
    }

    public function test_atamasiz_blok_silinebilir(): void
    {
        $block = $this->block();

        $this->actingAs($this->ipamUser())
            ->delete(route('addressing.destroy', $block))
            ->assertRedirect();

        $this->assertNull(AddressBlock::query()->find($block->id));
    }

    /**
     * @return array<string, mixed>
     */
    private function routePayload(NetworkSwitch $switch): array
    {
        return [
            'purpose' => AddressPurpose::Customer->value,
            'connection_type' => AddressConnectionType::NextHop->value,
            'core_switch_id' => $switch->uuid,
            'next_hop_source' => 'manual',
            'next_hop' => '10.0.0.2',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function block(array $attributes = []): AddressBlock
    {
        return AddressBlock::factory()->create([
            'network' => '10.9.9.0',
            'prefix_length' => 29,
            'purpose' => AddressPurpose::Customer,
            ...$attributes,
        ]);
    }

    private function ipamUser(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)
            ->syncResource($user, 'addressing', ['view', 'create', 'update', 'delete']);

        return $user->fresh();
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'hostname' => 'omurga',
            'management_ip' => '198.18.20.195',
            'role' => SwitchRole::Core,
        ]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
