<?php

declare(strict_types=1);

namespace Tests\Feature\Addressing;

use App\Enums\AddressConnectionType;
use App\Enums\AddressPurpose;
use App\Enums\CredentialProtocol;
use App\Enums\RouteProtocol;
use App\Enums\SwitchRole;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\SwitchRoute;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Adres bloğunun statik route ataması CİHAZA yazılır.
 *
 * Atama yalnızca panelde kaydedilseydi blok "route edilmiş" görünürken
 * trafiği kimse yönlendirmezdi: kayıt ile cihazın gerçeği ayrışırdı.
 */
final class RouteAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_atama_cihaza_route_yazar(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();
        $block = $this->block();

        $this->actingAs($this->admin())
            ->put(route('addressing.update', $block), $this->payload($switch))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = implode('', $device->written);

        $this->assertStringContainsString('<name>10.9.9.0/29</name>', $written);
        $this->assertStringContainsString('<next-hop>10.0.0.2</next-hop>', $written);
    }

    public function test_atama_kaldirilinca_route_cihazdan_silinir(): void
    {
        $switch = $this->switch();
        $block = $this->block([
            'connection_type' => AddressConnectionType::NextHop,
            'core_switch_id' => $switch->id,
            'next_hop' => '10.0.0.2',
        ]);

        // Cihazda karşılığı olan statik route.
        SwitchRoute::factory()->for($switch, 'switch')->create([
            'destination' => '10.9.9.0',
            'prefix_length' => 29,
            'next_hop' => '10.0.0.2',
            'protocol' => RouteProtocol::Static,
        ]);

        $device = $this->fakeDevice();

        $this->actingAs($this->admin())
            ->put(route('addressing.update', $block), [
                'purpose' => AddressPurpose::Customer->value,
                'description' => 'atama kaldırıldı',
                'reason' => 'test',
            ])
            ->assertRedirect();

        $this->assertStringContainsString('delete="delete"', implode('', $device->written));
    }

    public function test_ogrenilmis_route_silinmez(): void
    {
        $switch = $this->switch();
        $block = $this->block([
            'connection_type' => AddressConnectionType::NextHop,
            'core_switch_id' => $switch->id,
            'next_hop' => '10.0.0.2',
        ]);

        /*
         * Aynı hedefi taşıyan ama panelin yazmadığı bir yol: silmeye
         * çalışmak cihazda hata üretir ve zaten bizim satırımız değildir.
         */
        SwitchRoute::factory()->for($switch, 'switch')->create([
            'destination' => '10.9.9.0',
            'prefix_length' => 29,
            'protocol' => RouteProtocol::Bgp,
        ]);

        $device = $this->fakeDevice();

        $this->actingAs($this->admin())
            ->put(route('addressing.update', $block), [
                'purpose' => AddressPurpose::Customer->value,
                'reason' => 'test',
            ])
            ->assertRedirect();

        $this->assertStringNotContainsString('delete="delete"', implode('', $device->written));
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

    /**
     * @return array<string, mixed>
     */
    private function payload(NetworkSwitch $switch): array
    {
        return [
            'purpose' => AddressPurpose::Customer->value,
            'connection_type' => AddressConnectionType::NextHop->value,
            'core_switch_id' => $switch->uuid,
            'next_hop_source' => 'manual',
            'next_hop' => '10.0.0.2',
            'reason' => 'Müşteri bloğu yönlendirildi',
        ];
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'hostname' => 'omurga',
            'management_ip' => '198.18.20.195',

            // Omurga alanına yalnızca omurga rolündeki switch seçilebilir.
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

    private function fakeDevice(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    public function test_vlan_atamasi_gecit_adresini_cihaza_yazar(): void
    {
        $switch = $this->switch();

        // Hedef cihazda gerçekten tanımlı olmalı; panel var olmayan VLAN'a atamaz.
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 100]);

        $device = $this->fakeDevice();
        $block = $this->block();

        $this->actingAs($this->admin())
            ->put(route('addressing.update', $block), [
                'purpose' => AddressPurpose::Customer->value,
                'connection_type' => AddressConnectionType::Vlan->value,
                'core_switch_id' => $switch->uuid,
                'vlan_id' => $vlan->uuid,
                'reason' => 'Müşteri VLAN’ı açıldı',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = implode('', $device->written);

        /*
         * Geçit bloğun İLK KULLANILABİLİR adresidir: 10.9.9.0/29 için
         * 10.9.9.1/29 ve L3 arayüzü vlan.100.
         */
        $this->assertStringContainsString('10.9.9.1/29', $written);
        $this->assertStringContainsString('<name>vlan</name>', $written);
    }

    public function test_port_atamasi_portun_birimine_yazilir(): void
    {
        $switch = $this->switch();
        SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/5']);

        $device = $this->fakeDevice();
        $block = $this->block();

        $this->actingAs($this->admin())
            ->put(route('addressing.update', $block), [
                'purpose' => AddressPurpose::Customer->value,
                'connection_type' => AddressConnectionType::Port->value,
                'core_switch_id' => $switch->uuid,
                'port_name' => 'ge-0/0/5',
                'reason' => 'Müşteri portu verildi',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = implode('', $device->written);

        $this->assertStringContainsString('10.9.9.1/29', $written);
        $this->assertStringContainsString('ge-0/0/5', $written);
    }
}
