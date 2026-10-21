<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Models\NetworkSwitch;
use App\Models\SwitchPrefixList;
use App\Models\SwitchPrefixListEntry;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Prefix list ekranı cihaza yazar. Firewall filtreleri bu listelere dayanır;
 * boş bir liste, ona dayanan filtrenin hiçbir şeye uymaması demektir.
 */
final class PrefixListWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_liste_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.prefix-lists.store', $switch), ['name' => 'MUSTERI-ANONS'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertStringContainsString(
            '<policy-options><prefix-list><name>MUSTERI-ANONS</name></prefix-list></policy-options>',
            $device->payload(),
        );
    }

    public function test_prefix_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'koru']);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.prefix-lists.entries.store', [$switch, $list]), [
                'cidr' => '192.0.2.0/24',
            ])
            ->assertRedirect();

        $this->assertStringContainsString(
            '<prefix-list-item><name>192.0.2.0/24</name></prefix-list-item>',
            $device->payload(),
        );
    }

    public function test_prefix_cihazdan_silinir(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'koru']);
        $entry = SwitchPrefixListEntry::factory()->for($list, 'prefixList')
            ->create(['address' => '10.0.0.0', 'prefix_length' => 8]);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->delete(route('switches.prefix-lists.entries.destroy', [$switch, $list, $entry]))
            ->assertRedirect();

        $this->assertStringContainsString(
            '<prefix-list-item delete="delete"><name>10.0.0.0/8</name></prefix-list-item>',
            $device->payload(),
        );
    }

    public function test_liste_cihazdan_silinir(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'koru']);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->delete(route('switches.prefix-lists.destroy', [$switch, $list]))
            ->assertRedirect();

        $this->assertStringContainsString(
            '<prefix-list delete="delete"><name>koru</name></prefix-list>',
            $device->payload(),
        );
    }

    public function test_ayni_prefix_iki_kez_eklenemez(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create();
        SwitchPrefixListEntry::factory()->for($list, 'prefixList')
            ->create(['address' => '192.0.2.0', 'prefix_length' => 24]);
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.prefix-lists.entries.store', [$switch, $list]), [
                'cidr' => '192.0.2.0/24',
            ])
            ->assertSessionHasErrors('cidr');

        $this->assertNull($device->connection);
    }

    public function test_maskeye_hizasiz_ag_reddedilir(): void
    {
        $switch = $this->switch();
        $list = SwitchPrefixList::factory()->for($switch, 'switch')->create();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'configure']))
            ->post(route('switches.prefix-lists.entries.store', [$switch, $list]), [
                'cidr' => '192.0.2.5/24',
            ])
            ->assertSessionHasErrors('cidr');

        $this->assertNull($device->connection);
    }

    public function test_duzenleme_yetkisi_cihaza_yazmaya_yetmez(): void
    {
        $switch = $this->switch();
        $device = $this->fakeDevice();

        $this->actingAs($this->operator(['view', 'update']))
            ->post(route('switches.prefix-lists.store', $switch), ['name' => 'MUSTERI'])
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    /**
     * @param  list<string>  $actions
     */
    private function operator(array $actions): User
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

    private function fakeDevice(): FakeJunosDevice
    {
        $device = new FakeJunosDevice('[edit policy-options]');
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
