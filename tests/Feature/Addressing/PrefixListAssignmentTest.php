<?php

declare(strict_types=1);

namespace Tests\Feature\Addressing;

use App\Enums\AddressConnectionType;
use App\Enums\CredentialProtocol;
use App\Enums\SwitchRole;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchPrefixList;
use App\Models\SwitchPrefixListEntry;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\Support\FakeNetconfTransport;
use Tests\TestCase;

/**
 * Adres bloğunun prefix list ataması CİHAZA yazılır.
 *
 * Yalnız envantere yazılsaydı panel "bu blok omurgadan anons ediliyor" derdi
 * ama cihazda o satır olmazdı. Panelin söylediği ile cihazın gerçeği
 * ayrıldığında kimse fark etmez — bu projede en zararlı hata sınıfı budur.
 */
final class PrefixListAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_atama_cihazdaki_listeye_yazilir(): void
    {
        [$core, $list] = $this->coreWithList();
        $device = $this->device($list->name, ['192.0.2.0/24']);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), $this->payload($core, $list))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<policy-options>', $written);
        $this->assertStringContainsString('<name>musteri1-PREFIXES</name>', $written);
        $this->assertStringContainsString('<name>192.0.2.0/24</name>', $written);
    }

    /**
     * Cihaza yazmak ayrı bir yetkidir; adresleme yetkisi onu kapsamaz.
     */
    public function test_configure_yetkisi_olmadan_atama_yapilamaz(): void
    {
        [$core, $list] = $this->coreWithList();
        $device = $this->device($list->name);

        $this->actingAs($this->operator(switchActions: ['view']))
            ->post(route('addressing.store'), $this->payload($core, $list))
            ->assertSessionHasErrors('prefix_list_id');

        // Yetki reddedildiyse cihaza hiç bağlanılmamalı.
        $this->assertNull($device->connection);
        $this->assertDatabaseCount('address_blocks', 0);
    }

    /**
     * Atama kaldırılınca satır cihazdan da çıkarılır; yoksa envanterde
     * karşılığı olmayan bir prefix anons edilmeye devam ederdi.
     */
    public function test_atama_kaldirilinca_cihazdan_cikarilir(): void
    {
        [$core, $list] = $this->coreWithList();
        $block = $this->assignedBlock($core, $list);

        // Geri okumada liste artık boş: satır cihazdan çıktı.
        $device = $this->device($list->name);

        $this->actingAs($this->operator())
            ->put(route('addressing.update', $block), [
                'connection_type' => AddressConnectionType::NextHop->value,
                'next_hop_source' => 'manual',
                'next_hop' => '10.0.0.1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<prefix-list-item delete="delete"><name>192.0.2.0/24</name></prefix-list-item>',
            $device->payload(),
        );
        $this->assertNull($block->fresh()->switch_prefix_list_id);
    }

    public function test_blok_silinince_cihazdan_cikarilir(): void
    {
        [$core, $list] = $this->coreWithList();
        $block = $this->assignedBlock($core, $list);
        $device = $this->device($list->name);

        $this->actingAs($this->operator())
            ->delete(route('addressing.destroy', $block))
            ->assertRedirect();

        $this->assertStringContainsString(
            '<prefix-list-item delete="delete"><name>192.0.2.0/24</name></prefix-list-item>',
            $device->payload(),
        );
        $this->assertDatabaseCount('address_blocks', 0);
    }

    /**
     * Cihaza yazma başarısız olursa envanter değişikliği de geri alınır;
     * yarım kalmış bir atama panelde doğru sanılırdı.
     */
    public function test_cihaz_yazamazsa_envanter_de_degismez(): void
    {
        [$core, $list] = $this->coreWithList();

        /*
         * Yanıt vermeyen cihaz: oturum daha el sıkışmada düşer. Boş fark
         * HATA DEĞİLDİR ("değişiklik yok" demektir), o yüzden burada
         * kullanılamaz.
         */
        $this->app->instance(NetconfTransport::class, new FakeNetconfTransport);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), $this->payload($core, $list));

        $this->assertDatabaseCount('address_blocks', 0);
    }

    /**
     * Yaygın yapı: uplink çıkışındaki filtre "kaynağı şu listede olanı
     * kabul et, kalanı düşür" der. Bir blok o listede DEĞİLSE anons edilse
     * bile dışarı çıkamaz — o yüzden atama iki listeye birden yazılır.
     */
    public function test_blok_cikis_bekcisi_listesine_de_yazilir(): void
    {
        [$core, $list] = $this->coreWithList();
        $guard = SwitchPrefixList::factory()->for($core, 'switch')->create([
            'name' => 'koru',
            'egress_interfaces' => ['ge-0/0/23.0'],
        ]);

        $device = $this->device($list->name, ['192.0.2.0/24'], $guard->name);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), $this->payload($core, $list))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<name>musteri1-PREFIXES</name>', $written);
        $this->assertStringContainsString('<name>koru</name>', $written);
        // Aynı prefix iki listeye de yazılır.
        $this->assertSame(2, substr_count($written, '<name>192.0.2.0/24</name>'));
    }

    /**
     * Çıkış bekçisi olmayan switchte fazladan yazma yapılmaz.
     */
    public function test_bekci_yoksa_yalnizca_anons_listesine_yazilir(): void
    {
        [$core, $list] = $this->coreWithList();
        SwitchPrefixList::factory()->for($core, 'switch')->create([
            'name' => 'baska',
            'egress_interfaces' => null,
        ]);

        $device = $this->device($list->name, ['192.0.2.0/24']);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), $this->payload($core, $list))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('<name>baska</name>', $device->payload());
    }

    /**
     * @return array{0: NetworkSwitch, 1: SwitchPrefixList}
     */
    private function coreWithList(): array
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

        $list = SwitchPrefixList::factory()->for($core, 'switch')->create(['name' => 'musteri1-PREFIXES']);

        return [$core->refresh(), $list];
    }

    private function assignedBlock(NetworkSwitch $core, SwitchPrefixList $list): AddressBlock
    {
        SwitchPrefixListEntry::factory()->for($list, 'prefixList')
            ->create(['address' => '192.0.2.0', 'prefix_length' => 24]);

        return AddressBlock::factory()->network('192.0.2.0/24')->create([
            'core_switch_id' => $core->id,
            'connection_type' => AddressConnectionType::PrefixList,
            'switch_prefix_list_id' => $list->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(NetworkSwitch $core, SwitchPrefixList $list): array
    {
        return [
            'network' => '192.0.2.0',
            'prefix_length' => 24,
            'core_switch_id' => $core->uuid,
            'connection_type' => AddressConnectionType::PrefixList->value,
            'prefix_list_id' => $list->uuid,
        ];
    }

    /**
     * @param  list<string>  $entries
     */
    private function device(string $listName, array $entries = [], ?string $guardName = null): FakeJunosDevice
    {
        $items = implode('', array_map(
            static fn (string $cidr): string => "<prefix-list-item><name>{$cidr}</name></prefix-list-item>",
            $entries,
        ));

        // Bekçi liste geri okumada da bulunmalı; yoksa eşitleme onu siler.
        $guard = $guardName === null ? '' : "<prefix-list><name>{$guardName}</name></prefix-list>";

        $device = new FakeJunosDevice(
            policyOptions: '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><configuration><policy-options>'
                ."<prefix-list><name>{$listName}</name>{$items}</prefix-list>{$guard}"
                .'</policy-options></configuration></rpc-reply>'."\n]]>]]>",
        );

        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    /**
     * @param  list<string>  $switchActions
     */
    private function operator(array $switchActions = ['view', 'configure']): User
    {
        $user = User::factory()->create();
        $resolver = app(UserPermissionResolver::class);
        $resolver->syncResource($user, 'addressing', ['view', 'create', 'update', 'delete']);
        $resolver->syncResource($user, 'switches', $switchActions);

        return $user->fresh();
    }
}
