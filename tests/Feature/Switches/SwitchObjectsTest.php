<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchCommit;
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
 * Switch altındaki nesne ekranları: IP adresleri, route'lar, prefix listeler
 * ve commit geçmişi.
 */
final class SwitchObjectsTest extends TestCase
{
    use RefreshDatabase;

    private FakeJunosDevice $device;

    /**
     * IP adresi ekranı cihaza yazar; varsayılan yetki "configure".
     *
     * @param  array<int, string>  $actions
     */
    private function operator(array $actions = ['view', 'configure']): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', $actions);

        /*
         * Cihaz nesnelerinin (port, VLAN, route...) kendi yetkileri var;
         * switch yetkisi onları kapsamıyor. "configure" cihazın kendi
         * ayarlarıdır ve testin niyeti "cihaza yazabilen kullanıcı"dır;
         * "update" ise envanter kaydını düzenlemektir ve cihaza yazdırmaz.
         */
        in_array('configure', $actions, true)
            ? $this->grantDeviceWrite($user)
            : $this->grantDeviceView($user);

        return $user->fresh();
    }

    /**
     * Cihaza yazma yolu olan switch; sahte cihaz da bağlanır.
     */
    private function switchWithDevice(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        $this->device = new FakeJunosDevice('[edit interfaces]');
        $this->app->instance(NetconfTransport::class, $this->device);

        return $switch->refresh();
    }

    /** Adres yalnızca switche atanmış bir bloktan seçilebilir. */
    private function block(NetworkSwitch $switch, string $network = '198.18.21.0', int $prefix = 24): AddressBlock
    {
        // Aralık tek kaynaktan üretilir; fabrikanın network() durumu bunu yapar.
        return AddressBlock::factory()->network("{$network}/{$prefix}")->create([
            'is_subnetted' => false,
            'access_switch_id' => $switch->id,
        ]);
    }

    public function test_an_ip_address_can_be_added_to_an_interface(): void
    {
        $switch = $this->switchWithDevice();
        $this->block($switch);
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/1']);

        $this->actingAs($this->operator())
            ->post(route('switches.ip-addresses.store', $switch), [
                'attachment' => 'port',
                'address' => '198.18.21.1',
                'switch_port_id' => $port->uuid,
            ])
            ->assertRedirect();

        /*
         * Arayüz adı sorulmaz, portun 0 numaralı biriminden türetilir; maske
         * de adresin ait olduğu bloktan gelir. Envanter cihazdan geri okunur,
         * o yüzden burada cihaza NE yazıldığına bakılır.
         *
         * Porta adres vermek portu routed yapar: cihaz aynı arayüzde hem
         * ethernet-switching hem inet ailesini kabul etmiyor.
         */
        $this->assertStringContainsString('<name>ge-0/0/1</name>', $this->device->payload());
        $this->assertStringContainsString('<ethernet-switching delete="delete"/>', $this->device->payload());
        $this->assertStringContainsString('<address><name>198.18.21.1/24</name></address>', $this->device->payload());
    }

    public function test_the_same_address_cannot_repeat_on_one_interface(): void
    {
        $switch = $this->switchWithDevice();
        $this->block($switch);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['l3_interface' => 'irb.30']);
        SwitchIpAddress::factory()->for($switch, 'switch')
            ->create(['interface_name' => 'irb.30', 'address' => '198.18.21.1']);

        $this->actingAs($this->operator())
            ->post(route('switches.ip-addresses.store', $switch), [
                'attachment' => 'vlan',
                'address' => '198.18.21.1', 'vlan_id' => $vlan->uuid,
            ])
            ->assertSessionHasErrors('address');
    }

    public function test_a_port_of_another_switch_cannot_be_linked(): void
    {
        $switch = $this->switchWithDevice();
        $this->block($switch);
        $foreign = SwitchPort::factory()->for(NetworkSwitch::factory()->create(), 'switch')->create();

        $this->actingAs($this->operator())
            ->post(route('switches.ip-addresses.store', $switch), [
                'attachment' => 'port',
                'address' => '198.18.21.1',
                'prefix_length' => 24,
                'switch_port_id' => $foreign->uuid,
            ])
            ->assertSessionHasErrors('switch_port_id');
    }

    public function test_an_address_can_sit_on_a_vlan_instead_of_a_port(): void
    {
        $switch = $this->switchWithDevice();
        $this->block($switch, '198.18.1.56', 29);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['vlan_tag' => 25, 'l3_interface' => 'vlan.25']);

        $this->actingAs($this->operator())
            ->post(route('switches.ip-addresses.store', $switch), [
                'attachment' => 'vlan',
                'address' => '198.18.1.57',
                'vlan_id' => $vlan->uuid,
            ])
            ->assertRedirect();

        // VLAN'ın L3 arayüzüne yazılır; anahtarlama ailesine dokunulmaz.
        $this->assertStringContainsString('<name>vlan</name><unit><name>25</name>', $this->device->payload());
        $this->assertStringContainsString('<address><name>198.18.1.57/29</name></address>', $this->device->payload());
        $this->assertStringNotContainsString('ethernet-switching', $this->device->payload());
    }

    public function test_only_the_chosen_attachment_is_stored(): void
    {
        $switch = $this->switchWithDevice();
        $this->block($switch);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['l3_interface' => 'vlan.25']);
        $port = SwitchPort::factory()->for($switch, 'switch')->create();

        // Form port alanını da göndermiş olsa bile seçim VLAN ise port yok sayılır.
        $this->actingAs($this->operator())
            ->post(route('switches.ip-addresses.store', $switch), [
                'attachment' => 'vlan',
                'address' => '198.18.21.1',
                'vlan_id' => $vlan->uuid,
                'switch_port_id' => $port->uuid,
            ])
            ->assertRedirect();

        // Seçim VLAN olduğu için port yok sayılır: cihazda vlan.25'e yazılır.
        $this->assertStringContainsString('<name>vlan</name><unit><name>25</name>', $this->device->payload());
        $this->assertStringNotContainsString($port->name, $this->device->payload());
    }

    public function test_the_attachment_must_be_chosen(): void
    {
        $switch = $this->switchWithDevice();

        $this->actingAs($this->operator())
            ->post(route('switches.ip-addresses.store', $switch), ['address' => '198.18.21.1'])
            ->assertSessionHasErrors('attachment');
    }

    public function test_a_vlan_without_an_l3_interface_cannot_carry_an_address(): void
    {
        $switch = $this->switchWithDevice();
        $this->block($switch);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['l3_interface' => null]);

        // Arayüz adı VLAN'ın L3 arayüzünden türetiliyor; yoksa türetilecek ad yok.
        $this->actingAs($this->operator())
            ->post(route('switches.ip-addresses.store', $switch), [
                'attachment' => 'vlan',
                'address' => '198.18.21.1',
                'vlan_id' => $vlan->uuid,
            ])
            ->assertSessionHasErrors('vlan_id');
    }

    public function test_an_address_outside_the_switch_blocks_is_rejected(): void
    {
        $switch = $this->switchWithDevice();
        $this->block($switch);
        $vlan = Vlan::factory()->for($switch, 'switch')->create(['l3_interface' => 'vlan.25']);

        // 198.18.21.0/24 atanmış; bunun dışındaki adres kabul edilmez.
        $this->actingAs($this->operator())
            ->post(route('switches.ip-addresses.store', $switch), [
                'attachment' => 'vlan',
                'address' => '192.0.2.5',
                'vlan_id' => $vlan->uuid,
            ])
            ->assertSessionHasErrors('address');

        $this->assertNull($this->device->connection);
    }

    /*
     * Route ekranı artık veritabanına değil cihaza yazıyor; kapsamı
     * tests/Feature/Netconf/RouteWriteTest.php içinde.
     */

    /*
     * Prefix list ekranı da cihaza yazıyor; kapsamı
     * tests/Feature/Netconf/PrefixListWriteTest.php içinde.
     */

    public function test_the_commit_history_is_read_only(): void
    {
        $switch = NetworkSwitch::factory()->create();
        SwitchCommit::factory()->for($switch, 'switch')->create([
            'sequence_number' => 7, 'committed_by' => 'admin', 'comment' => 'vlan eklendi',
        ]);

        $this->actingAs($this->operator())
            ->get(route('switches.commits', $switch))
            ->assertOk()
            ->assertSee('vlan eklendi')
            ->assertSee('admin')
            ->assertSee('Cihazdan okunur, elle girilmez');
    }

    public function test_objects_of_another_switch_are_not_listed(): void
    {
        $switch = NetworkSwitch::factory()->create();
        $other = NetworkSwitch::factory()->create();
        SwitchIpAddress::factory()->for($other, 'switch')->create(['address' => '10.99.99.1']);

        $this->actingAs($this->operator())
            ->get(route('switches.ip-addresses', $switch))
            ->assertOk()
            ->assertDontSee('10.99.99.1');
    }

    public function test_a_read_only_user_cannot_change_objects(): void
    {
        $switch = NetworkSwitch::factory()->create();
        $user = $this->operator(['view']);

        $this->actingAs($user)->get(route('switches.routes', $switch))->assertOk();
        $this->actingAs($user)
            ->post(route('switches.routes.store', $switch), [
                'destination' => '10.0.0.0', 'prefix_length' => 8, 'protocol' => 'static',
            ])
            ->assertForbidden();
    }
}
