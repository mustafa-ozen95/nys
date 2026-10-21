<?php

declare(strict_types=1);

namespace Tests\Feature\Addressing;

use App\Enums\AddressPurpose;
use App\Enums\CredentialProtocol;
use App\Models\AddressBlock;
use App\Models\BgpGroup;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uplink ve BGP peering için ayrılan private havuzlar.
 *
 * Bu bloklar müşteri bloklarıyla aynı listede aranmamalı: peering için boş
 * bir /30 sorulduğunda müşteri bloğu aday çıkarsa yanlış adres dağıtılır.
 */
final class AddressPurposeTest extends TestCase
{
    use RefreshDatabase;

    public function test_blok_amaciyla_kaydedilir(): void
    {
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '10.0.0.0',
                'prefix_length' => 24,
                'purpose' => AddressPurpose::Peering->value,
                'description' => 'Peering havuzu',
            ])
            ->assertRedirect();

        $this->assertSame(
            AddressPurpose::Peering,
            AddressBlock::query()->firstWhere('network', '10.0.0.0')->purpose,
        );
    }

    /**
     * Peering havuzundan kesilen /30 da peering içindir; her alt ağda yeniden
     * sorulsaydı havuzun altında yanlış amaçlı bloklar birikirdi.
     */
    public function test_alt_ag_ust_blogun_amacini_devralir(): void
    {
        $pool = AddressBlock::factory()->network('10.0.0.0/24')
            ->create(['purpose' => AddressPurpose::Peering]);

        $this->actingAs($this->operator())
            ->post(route('addressing.subnets.store', $pool), [
                'network' => '10.0.0.4',
                'prefix_length' => 30,
                'description' => 'omurga-1 peering',
            ])
            ->assertRedirect();

        $this->assertSame(
            AddressPurpose::Peering,
            AddressBlock::query()->firstWhere('network', '10.0.0.4')->purpose,
        );
    }

    public function test_amac_verilmezse_musteri_blogudur(): void
    {
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), ['network' => '192.168.0.0', 'prefix_length' => 24])
            ->assertRedirect();

        $this->assertSame(
            AddressPurpose::Customer,
            AddressBlock::query()->firstWhere('network', '192.168.0.0')->purpose,
        );
    }

    /**
     * BGP komşu formu yalnızca altyapı havuzlarındaki, HENÜZ BÖLÜNMEMİŞ
     * blokları önerir.
     */
    public function test_bgp_ekrani_yalnizca_altyapi_bloklarini_onerir(): void
    {
        AddressBlock::factory()->network('10.0.0.4/30')->create([
            'purpose' => AddressPurpose::Peering,
            'description' => 'omurga-1 peering',
        ]);
        AddressBlock::factory()->network('192.0.2.0/24')->create([
            'purpose' => AddressPurpose::Customer,
        ]);
        // Bölünmüş blok bir havuzdur, adres taşımaz.
        AddressBlock::factory()->network('10.9.0.0/24')->create([
            'purpose' => AddressPurpose::Peering,
            'is_subnetted' => true,
        ]);

        $switch = $this->switch();
        // Havuz seçici komşu formundadır; form ise bir grubun altındadır.
        BgpGroup::factory()->for($switch, 'switch')->create();

        $this->actingAs($this->switchOperator())
            ->get(route('switches.bgp', $switch))
            ->assertOk()
            ->assertSee('10.0.0.4/30')
            ->assertDontSee('192.0.2.0/24')
            ->assertDontSee('10.9.0.0/24');
    }

    /**
     * Müşteri blokları ile altyapının private blokları AYRI listelerde
     * durur; aynı listede olmaları ikisini de okunmaz hâle getiriyordu.
     */
    public function test_musteri_ve_altyapi_bloklari_ayri_listelenir(): void
    {
        AddressBlock::factory()->network('192.0.2.0/24')
            ->create(['purpose' => AddressPurpose::Customer]);
        AddressBlock::factory()->network('10.0.0.0/24')
            ->create(['purpose' => AddressPurpose::Peering]);

        $this->actingAs($this->operator())
            ->get(route('addressing.index'))
            ->assertOk()
            ->assertSee('192.0.2.0/24')
            ->assertDontSee('10.0.0.0/24');

        $this->actingAs($this->operator())
            ->get(route('addressing.index', ['tur' => 'altyapi']))
            ->assertOk()
            ->assertSee('10.0.0.0/24')
            ->assertDontSee('192.0.2.0/24');
    }

    /**
     * Derin bağlantı doğru sekmeyi açar; yoksa seçili blok boş bir listeye
     * düşerdi.
     */
    public function test_secili_blok_sekmeyi_belirler(): void
    {
        $block = AddressBlock::factory()->network('10.0.0.0/24')
            ->create(['purpose' => AddressPurpose::Uplink]);

        $this->actingAs($this->operator())
            ->get(route('addressing.index', ['block' => $block->uuid]))
            ->assertOk()
            ->assertSee('10.0.0.0/24');
    }

    /**
     * @param  array<int, string>  $actions
     */
    private function operator(array $actions = ['view', 'create', 'update', 'delete']): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'addressing', $actions);

        return $user->fresh();
    }

    private function switchOperator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'management_ip' => '198.18.20.195',
            'autonomous_system' => '65000',
        ]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
