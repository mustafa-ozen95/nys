<?php

declare(strict_types=1);

namespace Tests\Feature\Addressing;

use App\Enums\AddressConnectionType;
use App\Enums\CredentialProtocol;
use App\Enums\SwitchRole;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\SwitchPrefixList;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

final class AddressBlockTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, string>  $actions
     */
    private function operator(array $actions = ['view', 'create', 'update', 'delete']): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'addressing', $actions);

        /*
         * Prefix list ataması omurga switche yazıldığı için o switchte
         * "configure" yetkisi de gerekir; adresleme yetkisi tek başına
         * cihaza yazmaya yetmez.
         */
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }

    /**
     * Kök blok KAPALI bir dal olarak listelenir; alt ağı içindedir.
     *
     * Satır yerinde açılır: alt ağ sayfada durur, dal kapalı olduğu için
     * görünmez. Alt ağ yalnız üst blok seçilince yan sütunda görünseydi bir
     * /24'ün neresinin boş olduğunu görmek için sütun değiştirmek ve
     * boşlukları kafadan hesaplamak gerekirdi.
     */
    public function test_the_screen_lists_root_blocks_as_collapsed_branches(): void
    {
        $root = AddressBlock::factory()->network('10.0.0.0/8')->create();
        AddressBlock::factory()->network('10.1.0.0/16')->create(['parent_id' => $root->id]);

        $yanit = $this->actingAs($this->operator())
            ->get(route('addressing.index'))
            ->assertOk()
            ->assertSee('10.0.0.0/8')
            ->assertSee('10.1.0.0/16');

        /* Dal KAPALI gelir: kök listesi tek bakışta okunabilir kalmalı. */
        $this->assertStringContainsString('<details class="agac__dal"', $yanit->getContent());
        $this->assertStringNotContainsString('<details class="agac__dal" open', $yanit->getContent());
    }

    public function test_selecting_a_block_shows_its_subnets_and_range(): void
    {
        $root = AddressBlock::factory()->network('10.0.0.0/8')->create();
        AddressBlock::factory()->network('10.1.0.0/16')->create(['parent_id' => $root->id]);

        $this->actingAs($this->operator())
            ->get(route('addressing.index', ['block' => $root->uuid]))
            ->assertOk()
            ->assertSee('10.1.0.0/16')
            ->assertSee('10.255.255.255')
            ->assertSee('255.0.0.0');
    }

    public function test_a_root_block_can_be_created(): void
    {
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), ['network' => '192.168.0.0', 'prefix_length' => 16, 'description' => 'Ofis'])
            ->assertRedirect();

        $this->assertDatabaseHas('address_blocks', [
            'network' => '192.168.0.0',
            'prefix_length' => 16,
            'parent_id' => null,
            'description' => 'Ofis',
        ]);
    }

    public function test_an_address_that_is_not_the_network_address_is_rejected(): void
    {
        // Sessizce 198.18.20.0/24'e çevrilirse kullanıcı yanlış blok kaydettiğini
        // fark etmez; bu yüzden reddedilir ve doğrusu söylenir.
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), ['network' => '198.18.20.7', 'prefix_length' => 24])
            ->assertSessionHasErrors('network');

        $this->assertDatabaseCount('address_blocks', 0);
    }

    public function test_reserved_ranges_are_rejected(): void
    {
        foreach ([['127.0.0.0', 8], ['169.254.0.0', 16], ['224.0.0.0', 4], ['0.0.0.0', 8]] as [$adres, $maske]) {
            $this->actingAs($this->operator())
                ->post(route('addressing.store'), ['network' => $adres, 'prefix_length' => $maske])
                ->assertSessionHasErrors('network');
        }

        $this->assertDatabaseCount('address_blocks', 0);
    }

    public function test_a_mask_outside_the_allowed_range_is_rejected(): void
    {
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), ['network' => '10.0.0.0', 'prefix_length' => 33])
            ->assertSessionHasErrors('prefix_length');
    }

    public function test_an_overlapping_block_is_rejected(): void
    {
        AddressBlock::factory()->network('10.0.0.0/8')->create();

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), ['network' => '10.4.0.0', 'prefix_length' => 16])
            ->assertSessionHasErrors('network');

        $this->assertDatabaseCount('address_blocks', 1);
    }

    public function test_a_subnet_outside_its_parent_is_rejected(): void
    {
        $root = AddressBlock::factory()->network('10.0.0.0/8')->create();

        $this->actingAs($this->operator())
            ->post(route('addressing.subnets.store', $root), ['network' => '192.168.1.0', 'prefix_length' => 24])
            ->assertSessionHasErrors('network');

        $this->assertDatabaseCount('address_blocks', 1);
    }

    public function test_adding_a_subnet_marks_the_parent_as_subnetted(): void
    {
        $root = AddressBlock::factory()->network('10.0.0.0/8')->create(['is_subnetted' => false]);

        $this->actingAs($this->operator())
            ->post(route('addressing.subnets.store', $root), ['network' => '10.1.0.0', 'prefix_length' => 16])
            // Eklenen alt ağ seçili gelir.
            ->assertRedirect(route('addressing.index', [
                'block' => AddressBlock::query()->where('network', '10.1.0.0')->value('uuid'),
            ]));

        $this->assertTrue($root->fresh()->is_subnetted);
        $this->assertDatabaseHas('address_blocks', ['network' => '10.1.0.0', 'parent_id' => $root->id]);
    }

    public function test_deleting_the_last_subnet_clears_the_subnetted_flag(): void
    {
        $root = AddressBlock::factory()->network('10.0.0.0/8')->create(['is_subnetted' => true]);
        $subnet = AddressBlock::factory()->network('10.1.0.0/16')->create(['parent_id' => $root->id]);

        $this->actingAs($this->operator())
            ->delete(route('addressing.destroy', $subnet))
            ->assertRedirect(route('addressing.index', ['block' => $root->uuid]));

        $this->assertDatabaseMissing('address_blocks', ['id' => $subnet->id]);
        $this->assertFalse($root->fresh()->is_subnetted);
    }

    public function test_a_block_can_be_assigned_to_a_switch_without_being_routed(): void
    {
        $core = NetworkSwitch::factory()->create(['role' => SwitchRole::Core]);

        // /24 önce omurgaya eklenir; VLAN/port ataması sonra gelebilir.
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), ['network' => '198.18.21.0', 'prefix_length' => 24, 'core_switch_id' => $core->uuid])
            ->assertRedirect();

        $block = AddressBlock::query()->firstWhere('network', '198.18.21.0');

        $this->assertSame($core->id, $block->core_switch_id);
        $this->assertNull($block->access_switch_id);
        $this->assertNull($block->connection_type);
        $this->assertFalse($block->is_assigned);
    }

    public function test_a_subnet_can_be_routed_to_another_switch_vlan(): void
    {
        $core = NetworkSwitch::factory()->create(['role' => SwitchRole::Core]);
        $access = NetworkSwitch::factory()->create(['role' => SwitchRole::Access]);
        $vlan = Vlan::factory()->for($access, 'switch')->create(['vlan_tag' => 30, 'name' => 'kamera']);
        $root = AddressBlock::factory()->network('198.18.21.0/24')->create(['core_switch_id' => $core->id]);

        $this->actingAs($this->operator())
            ->post(route('addressing.subnets.store', $root), [
                'network' => '198.18.21.8', 'prefix_length' => 29,
                'core_switch_id' => $core->uuid,
                'access_switch_id' => $access->uuid,
                'connection_type' => 'vlan',
                'vlan_id' => $vlan->uuid,
            ])
            ->assertRedirect();

        $subnet = AddressBlock::query()->firstWhere('network', '198.18.21.8');

        $this->assertSame($core->id, $subnet->core_switch_id);
        $this->assertSame($access->id, $subnet->access_switch_id);
        $this->assertSame(AddressConnectionType::Vlan, $subnet->connection_type);
        $this->assertSame($vlan->id, $subnet->vlan_id);
        $this->assertSame('VLAN 30', $subnet->assignment_target);
        $this->assertTrue($subnet->is_assigned);
    }

    public function test_a_block_can_be_routed_to_a_port(): void
    {
        $switch = NetworkSwitch::factory()->create();
        SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/12']);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '10.40.0.0', 'prefix_length' => 24,
                'access_switch_id' => $switch->uuid,
                'connection_type' => 'port',
                'port_name' => 'ge-0/0/12',
            ])
            ->assertRedirect();

        $block = AddressBlock::query()->firstWhere('network', '10.40.0.0');

        $this->assertSame('ge-0/0/12', $block->port_name);
        $this->assertSame('ge-0/0/12', $block->assignment_target);
        $this->assertNull($block->vlan_id);
    }

    public function test_routing_requires_a_switch_and_a_target(): void
    {
        // VLAN ve port omurgada da olabilir; hiç switch seçilmezse hedef
        // belirsizdir ve hata omurga alanında raporlanır.
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), ['network' => '10.50.0.0', 'prefix_length' => 24, 'connection_type' => 'vlan'])
            ->assertSessionHasErrors(['core_switch_id', 'vlan_id']);

        // Switchte tanımlı olmayan VLAN'a route edilemez.
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '10.51.0.0', 'prefix_length' => 24,
                'access_switch_id' => NetworkSwitch::factory()->create()->uuid,
                'connection_type' => 'vlan',
                'vlan_id' => Str::uuid()->toString(),
            ])
            ->assertSessionHasErrors('vlan_id');

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '10.50.0.0', 'prefix_length' => 24,
                'access_switch_id' => NetworkSwitch::factory()->create()->uuid,
                'connection_type' => 'port',
            ])
            ->assertSessionHasErrors('port_name');
    }

    public function test_an_assignment_can_be_changed_and_cleared(): void
    {
        $switch = NetworkSwitch::factory()->create();
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 10]);
        SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/1']);
        $block = AddressBlock::factory()->network('10.60.0.0/24')->create([
            'access_switch_id' => $switch->id,
            'connection_type' => AddressConnectionType::Vlan,
            'vlan_id' => $vlan->id,
        ]);

        $this->actingAs($this->operator())
            ->put(route('addressing.update', $block), [
                'access_switch_id' => $switch->uuid,
                'connection_type' => 'port',
                'port_name' => 'ge-0/0/1',
            ])
            ->assertRedirect();

        $block->refresh();

        // Tür değişince kullanılmayan alan temizlenir, eski VLAN kalmaz.
        $this->assertSame('ge-0/0/1', $block->port_name);
        $this->assertNull($block->vlan_id);

        $this->actingAs($this->operator())
            ->put(route('addressing.update', $block), ['access_switch_id' => ''])
            ->assertRedirect();

        $block->refresh();

        $this->assertNull($block->access_switch_id);
        $this->assertNull($block->connection_type);
        $this->assertNull($block->port_name);
        $this->assertFalse($block->is_assigned);
    }

    public function test_the_screen_shows_which_subnets_are_in_use(): void
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'sw-test-1']);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 70]);
        $root = AddressBlock::factory()->network('10.70.0.0/24')->create();
        AddressBlock::factory()->network('10.70.0.8/29')->create([
            'parent_id' => $root->id,
            'access_switch_id' => $switch->id,
            'connection_type' => AddressConnectionType::Vlan,
            'vlan_id' => $vlan->id,
        ]);
        AddressBlock::factory()->network('10.70.0.16/29')->create(['parent_id' => $root->id]);

        $this->actingAs($this->operator())
            ->get(route('addressing.index', ['block' => $root->uuid]))
            ->assertOk()
            ->assertSee('sw-test-1')
            ->assertSee('VLAN 70')
            ->assertSee('Atanmadı');
    }

    public function test_a_deep_subnet_keeps_its_ancestors_on_screen(): void
    {
        $root = AddressBlock::factory()->network('10.80.0.0/24')->create();
        $middle = AddressBlock::factory()->network('10.80.0.0/27')->create(['parent_id' => $root->id]);
        $leaf = AddressBlock::factory()->network('10.80.0.8/29')->create(['parent_id' => $middle->id]);

        $this->actingAs($this->operator())
            ->get(route('addressing.index', ['block' => $leaf->uuid]))
            ->assertOk()
            ->assertSee('10.80.0.0/24')
            ->assertSee('10.80.0.0/27')
            ->assertSee('10.80.0.8/29');
    }

    public function test_a_block_keeps_a_long_note_and_shows_it_on_the_detail_panel(): void
    {
        $aciklama = "Kamera ağı.\nDVR 198.18.21.9 üzerinde, kayıt 30 gün.\nSwitch portu değişirse NOC'a haber verilecek.";

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '198.18.21.0', 'prefix_length' => 24,
                'description' => 'Kamera',
                'notes' => $aciklama,
            ])
            ->assertRedirect();

        $block = AddressBlock::query()->firstWhere('network', '198.18.21.0');

        $this->assertSame($aciklama, $block->notes);

        $this->actingAs($this->operator())
            ->get(route('addressing.index', ['block' => $block->uuid]))
            ->assertOk()
            ->assertSee('kayıt 30 gün', escape: false);
    }

    /**
     * Anons ayrı bir alan değildir: blok omurgadaki bir prefix list'e
     * girmişse anons ediliyordur.
     */
    public function test_a_block_in_a_core_prefix_list_is_announced(): void
    {
        $core = $this->coreSwitchThatCanBeConfigured();
        $list = SwitchPrefixList::factory()->for($core, 'switch')->create(['name' => 'musteri1-PREFIXES']);
        $device = $this->fakeDevice('musteri1-PREFIXES', ['192.0.2.0/24']);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.0.2.0',
                'prefix_length' => 24,
                'core_switch_id' => $core->uuid,
                'connection_type' => AddressConnectionType::PrefixList->value,
                'prefix_list_id' => $list->uuid,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $block = AddressBlock::query()->firstWhere('network', '192.0.2.0');

        $this->assertTrue($block->is_announced);
        $this->assertSame($core->id, $block->core_switch_id);
        $this->assertSame($list->id, $block->switch_prefix_list_id);
        // Prefix list omurgadadır; erişim switchiyle ilgisi yoktur.
        $this->assertNull($block->access_switch_id);

        // Atama artık envanterde kalmaz, CİHAZA yazılır.
        $this->assertStringContainsString(
            '<prefix-list-item><name>192.0.2.0/24</name></prefix-list-item>',
            $device->payload(),
        );
    }

    public function test_a_block_outside_a_prefix_list_is_not_announced(): void
    {
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), ['network' => '192.0.2.0', 'prefix_length' => 24])
            ->assertRedirect();

        $this->assertDatabaseHas('address_blocks', [
            'network' => '192.0.2.0',
            'is_announced' => false,
        ]);
    }

    public function test_an_announced_prefix_cannot_be_longer_than_24(): void
    {
        $core = NetworkSwitch::factory()->create(['role' => SwitchRole::Core]);
        $list = SwitchPrefixList::factory()->for($core, 'switch')->create();

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.0.2.0',
                'prefix_length' => 25,
                'core_switch_id' => $core->uuid,
                'connection_type' => AddressConnectionType::PrefixList->value,
                'prefix_list_id' => $list->uuid,
            ])
            ->assertSessionHasErrors('prefix_length');

        $this->assertDatabaseCount('address_blocks', 0);
    }

    /**
     * Anons edilmeyen blok bir erişim switchine bağlanabilir; anons bayrağı
     * bu alanları yasaklamaz.
     */
    public function test_a_block_may_be_assigned_to_an_access_switch(): void
    {
        $access = NetworkSwitch::factory()->create(['role' => SwitchRole::Access]);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.0.2.0',
                'prefix_length' => 24,
                'access_switch_id' => $access->uuid,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_a_non_announced_block_may_be_narrower_than_24(): void
    {
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), ['network' => '198.18.21.8', 'prefix_length' => 29])
            ->assertRedirect();

        $this->assertDatabaseHas('address_blocks', ['network' => '198.18.21.8', 'is_announced' => false]);
    }

    /**
     * Anons ayrı bir rozet değildir: listede bloğun girdiği prefix list'in
     * adı görünür, anons bilgisi de odur.
     */
    public function test_the_screen_shows_the_prefix_list_as_the_target(): void
    {
        $core = NetworkSwitch::factory()->create(['role' => SwitchRole::Core]);
        $list = SwitchPrefixList::factory()->for($core, 'switch')->create(['name' => 'musteri1-PREFIXES']);

        AddressBlock::factory()->network('192.0.2.0/24')->create([
            'is_announced' => true,
            'core_switch_id' => $core->id,
            'connection_type' => AddressConnectionType::PrefixList,
            'switch_prefix_list_id' => $list->id,
        ]);

        $this->actingAs($this->operator())
            ->get(route('addressing.index'))
            ->assertOk()
            ->assertSee('musteri1-PREFIXES');
    }

    public function test_an_invalid_address_is_rejected(): void
    {
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), ['network' => '10.0.0.256', 'prefix_length' => 24])
            ->assertSessionHasErrors('network');
    }

    public function test_a_user_without_permission_cannot_reach_the_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('addressing.index'))
            ->assertForbidden();
    }

    public function test_a_read_only_user_cannot_create_or_delete(): void
    {
        $user = $this->operator(['view']);
        $block = AddressBlock::factory()->network('10.0.0.0/8')->create();

        $this->actingAs($user)->post(route('addressing.store'), ['network' => '172.16.0.0', 'prefix_length' => 12])->assertForbidden();
        $this->actingAs($user)->delete(route('addressing.destroy', $block))->assertForbidden();

        $this->assertDatabaseCount('address_blocks', 1);
    }

    /**
     * Prefix list ataması artık CİHAZA yazar: omurga switchte "configure"
     * yetkisi ve NETCONF kimliği gerekir.
     */
    private function coreSwitchThatCanBeConfigured(): NetworkSwitch
    {
        $core = NetworkSwitch::factory()->create([
            'role' => SwitchRole::Core,
            'management_ip' => '198.18.20.195',
        ]);

        $core->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $core->refresh();
    }

    /**
     * Yazma sonrası cihazdan GERİ OKUNAN prefix list.
     *
     * Boş bırakılırsa eşitleme listeyi cihazda yok sayar ve envanterden siler
     * — bu, gerçek cihazın davranışı değildir: yazdığımız satır orada durur.
     *
     * @param  list<string>  $entries
     */
    private function fakeDevice(string $listName = 'musteri1-PREFIXES', array $entries = []): FakeJunosDevice
    {
        $items = implode('', array_map(
            static fn (string $cidr): string => "<prefix-list-item><name>{$cidr}</name></prefix-list-item>",
            $entries,
        ));

        $device = new FakeJunosDevice(
            policyOptions: '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration><policy-options>'
                ."<prefix-list><name>{$listName}</name>{$items}</prefix-list>"
                .'</policy-options></configuration></rpc-reply>'."\n]]>]]>",
        );

        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
