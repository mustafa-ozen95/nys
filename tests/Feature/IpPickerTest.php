<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AddressPurpose;
use App\Enums\CredentialProtocol;
use App\Models\AddressBlock;
use App\Models\BgpGroup;
use App\Models\NetworkSwitch;
use App\Models\SwitchIpAddress;
use App\Models\SwitchPort;
use App\Models\SwitchPrefixList;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * IP adresi panelde HER YERDE listeden seçilir.
 *
 * Serbest yazım envanterde karşılığı olmayan adres üretiyor, aynı adresi iki
 * yere verdiriyor ve bir bloğun gerçekten kullanılıp kullanılmadığını
 * Adresleme'den okunamaz hâle getiriyordu. Kaynak tektir: Adresleme.
 *
 * Elle giriş yalnızca belgelenmiş özel durumlarda açıktır (sağlayıcı adresi,
 * envantere yeni alınan cihaz) ve o zaman da listenin sonundaki seçenekle.
 */
final class IpPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_formunda_yonetim_adresi_adreslemeden_gelir(): void
    {
        AddressBlock::factory()->network('198.18.20.0/24')->create([
            'purpose' => AddressPurpose::Management,
            'description' => 'Yönetim ağı',
        ]);

        $this->actingAs($this->operator())
            ->get(route('switches.index'))
            ->assertOk()
            ->assertSee('198.18.20.0/24 · Yönetim · Yönetim ağı')
            ->assertSee('value="198.18.20.7"', escape: false);
    }

    public function test_bgp_komsu_adresi_peering_bloklarindan_gelir(): void
    {
        $switch = $this->switch();
        BgpGroup::factory()->for($switch, 'switch')->create();

        AddressBlock::factory()->network('198.18.8.36/30')->create([
            'purpose' => AddressPurpose::Peering,
            'description' => 'Peering',
        ]);

        // Müşteri bloğu peering listesine karışmamalı.
        AddressBlock::factory()->network('192.0.2.0/24')->create([
            'purpose' => AddressPurpose::Customer,
        ]);

        $this->actingAs($this->operator())
            ->get(route('switches.bgp', $switch))
            ->assertOk()
            ->assertSee('value="198.18.8.37"', escape: false)
            ->assertSee('value="198.18.8.38"', escape: false)
            ->assertDontSee('value="192.0.2.1"', escape: false);
    }

    public function test_prefix_list_agi_adreslemeden_secilir(): void
    {
        $switch = $this->switch();
        SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'koru']);

        AddressBlock::factory()->network('192.0.2.0/24')->create([
            'purpose' => AddressPurpose::Customer,
        ]);

        $this->actingAs($this->operator())
            ->get(route('switches.prefix-lists', $switch))
            ->assertOk()
            ->assertSee('value="192.0.2.0/24"', escape: false);
    }

    public function test_route_sonraki_atlamasi_uplink_havuzundan_gelir(): void
    {
        $switch = $this->switch();

        AddressBlock::factory()->network('198.18.8.36/30')->create([
            'purpose' => AddressPurpose::Uplink,
            'description' => 'Uplink bacağı',
        ]);

        $this->actingAs($this->operator())
            ->get(route('switches.routes', $switch))
            ->assertOk()
            ->assertSee('value="198.18.8.37"', escape: false)
            /*
             * Yolun ne yapacağı artık AÇIK bir seçimdir; sonraki atlamayı
             * boş bırakmak sessizce discard demiyor.
             */
            ->assertSee('Düşür (discard)')
            ->assertSee('Reddet (reject)');
    }

    public function test_cihazdaki_adres_blogu_olmasa_da_duzenlenebilir(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/23']);

        // Adres cihazdan okunmuştur; Adresleme'de karşılığı yoktur.
        $address = SwitchIpAddress::factory()->for($switch, 'switch')->create([
            'switch_port_id' => $port->id,
            'interface_name' => 'ge-0/0/23.0',
            'address' => '198.18.8.38',
            'prefix_length' => 30,
        ]);

        $this->actingAs($this->operator())
            ->get(route('switches.ip-addresses', $switch))
            ->assertOk()
            ->assertSee('Cihazdaki adres')
            ->assertSee('value="198.18.8.38"', escape: false);

        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        $secondPort = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/22']);

        // Yalnızca portu değişiyor; adres aynı kalıyor ve reddedilmemeli.
        $this->actingAs($this->operator())
            ->put(route('switches.ip-addresses.update', [$switch, $address]), [
                'attachment' => 'port',
                'switch_port_id' => $secondPort->uuid,
                'address' => '198.18.8.38',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertStringContainsString('198.18.8.38/30', implode('', $device->written));
    }

    public function test_yeni_adres_blogun_disindan_verilemez(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/23']);
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        $this->actingAs($this->operator())
            ->post(route('switches.ip-addresses.store', $switch), [
                'attachment' => 'port',
                'switch_port_id' => $port->uuid,
                'address' => '198.18.8.38',
            ])
            ->assertSessionHasErrors('address');

        $this->assertSame([], $device->written);
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

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'jn32-test', 'management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
