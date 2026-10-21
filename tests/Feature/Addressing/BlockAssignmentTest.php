<?php

declare(strict_types=1);

namespace Tests\Feature\Addressing;

use App\Enums\AddressConnectionType;
use App\Enums\CredentialProtocol;
use App\Enums\SwitchRole;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchPrefixList;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Bir blok dört farklı yere bağlanabilir ve her birinin kendi zorunlulukları
 * vardır. Form da doğrulama da bunları birbirinden ayırmalı.
 */
final class BlockAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sonraki atlama IP'sine route etmek için erişim switchi gerekmez;
     * hedef bir router ya da güvenlik duvarı olabilir.
     */
    public function test_ipye_route_icin_erisim_switchi_gerekmez(): void
    {
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.168.10.0',
                'prefix_length' => 24,
                'connection_type' => AddressConnectionType::NextHop->value,
                'next_hop_source' => 'manual',
                'next_hop' => '192.168.10.1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('address_blocks', [
            'network' => '192.168.10.0',
            'connection_type' => AddressConnectionType::NextHop->value,
            'next_hop' => '192.168.10.1',
            'access_switch_id' => null,
        ]);
    }

    public function test_ipye_route_secilirse_adres_zorunlu(): void
    {
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.168.10.0',
                'prefix_length' => 24,
                'connection_type' => AddressConnectionType::NextHop->value,
                'next_hop_source' => 'manual',
            ])
            ->assertSessionHasErrors('next_hop');
    }

    /**
     * Sonraki atlama envanterdeki bir switchin uplink adresinden de
     * seçilebilir; müşteri switchleri için elle giriş kalır.
     */
    public function test_sonraki_atlama_switch_uplinkinden_secilebilir(): void
    {
        NetworkSwitch::factory()->create(['role' => SwitchRole::Core, 'uplink_ip' => '198.18.8.37']);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.168.10.0',
                'prefix_length' => 24,
                'connection_type' => AddressConnectionType::NextHop->value,
                'next_hop_source' => 'switch',
                'next_hop_switch' => '198.18.8.37',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('address_blocks', [
            'network' => '192.168.10.0',
            'next_hop' => '198.18.8.37',
        ]);
    }

    /**
     * Prefix list omurga switchte durur:
     * set policy-options prefix-list musteri1-PREFIXES 192.0.2.0/24
     */
    public function test_prefix_list_omurga_switchten_secilir(): void
    {
        $omurga = $this->coreSwitchThatCanBeConfigured();
        $liste = SwitchPrefixList::factory()->for($omurga, 'switch')->create(['name' => 'musteri1-PREFIXES']);
        $cihaz = $this->fakeDevice('musteri1-PREFIXES', ['192.0.2.0/24']);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.0.2.0',
                'prefix_length' => 24,
                'core_switch_id' => $omurga->uuid,
                'connection_type' => AddressConnectionType::PrefixList->value,
                'prefix_list_id' => $liste->uuid,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('address_blocks', [
            'network' => '192.0.2.0',
            'switch_prefix_list_id' => $liste->id,
            'access_switch_id' => null,
        ]);

        $this->assertStringContainsString('192.0.2.0/24', $cihaz->payload());
    }

    /**
     * Liste seçilen omurga switche ait olmalı; başka switchin listesi
     * seçilemez.
     */
    public function test_baska_switchin_prefix_listesi_secilemez(): void
    {
        $omurga = NetworkSwitch::factory()->create(['role' => SwitchRole::Core]);
        $baska = NetworkSwitch::factory()->create(['role' => SwitchRole::Core]);
        $liste = SwitchPrefixList::factory()->for($baska, 'switch')->create();

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.0.2.0',
                'prefix_length' => 24,
                'core_switch_id' => $omurga->uuid,
                'connection_type' => AddressConnectionType::PrefixList->value,
                'prefix_list_id' => $liste->uuid,
            ])
            ->assertSessionHasErrors('prefix_list_id');
    }

    public function test_prefix_list_icin_omurga_switch_zorunlu(): void
    {
        $omurga = NetworkSwitch::factory()->create(['role' => SwitchRole::Core]);
        $liste = SwitchPrefixList::factory()->for($omurga, 'switch')->create();

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.0.2.0',
                'prefix_length' => 24,
                'connection_type' => AddressConnectionType::PrefixList->value,
                'prefix_list_id' => $liste->uuid,
            ])
            ->assertSessionHasErrors('core_switch_id');
    }

    /**
     * VLAN ve port yalnızca erişim switchinde olmak zorunda değil; omurgada
     * da olabilir. O durumda erişim switchi boş bırakılır.
     */
    public function test_vlan_omurga_switchte_de_olabilir(): void
    {
        $omurga = NetworkSwitch::factory()->create(['role' => SwitchRole::Core]);
        $vlan = Vlan::factory()->for($omurga, 'switch')->create(['vlan_tag' => 30]);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.168.10.0',
                'prefix_length' => 24,
                'core_switch_id' => $omurga->uuid,
                'connection_type' => AddressConnectionType::Vlan->value,
                'vlan_id' => $vlan->uuid,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('address_blocks', [
            'network' => '192.168.10.0',
            'core_switch_id' => $omurga->id,
            'access_switch_id' => null,
            'vlan_id' => $vlan->id,
        ]);
    }

    /**
     * VLAN ve port bir switchin üzerindedir; hiçbiri seçilmezse hedef
     * belirsizdir.
     */
    public function test_vlan_icin_en_az_bir_switch_gerekir(): void
    {
        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.168.10.0',
                'prefix_length' => 24,
                'connection_type' => AddressConnectionType::Vlan->value,
            ])
            ->assertSessionHasErrors('core_switch_id');
    }

    /**
     * Erişim switchi seçilmişse hedef ondan okunur; omurgadaki aynı etiketli
     * VLAN kabul edilmez.
     */
    public function test_erisim_secilirse_hedef_ondan_okunur(): void
    {
        $omurga = NetworkSwitch::factory()->create(['role' => SwitchRole::Core]);
        $erisim = NetworkSwitch::factory()->create(['role' => SwitchRole::Access]);
        $omurgaVlan = Vlan::factory()->for($omurga, 'switch')->create(['vlan_tag' => 30]);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.168.10.0',
                'prefix_length' => 24,
                'core_switch_id' => $omurga->uuid,
                'access_switch_id' => $erisim->uuid,
                'connection_type' => AddressConnectionType::Vlan->value,
                'vlan_id' => $omurgaVlan->uuid,
            ])
            ->assertSessionHasErrors('vlan_id');
    }

    /**
     * Alanın adı rolü söylüyorsa doğrulama da rolü kontrol etmeli.
     */
    public function test_omurga_alanina_erisim_switchi_secilemez(): void
    {
        $erisim = NetworkSwitch::factory()->create(['role' => SwitchRole::Access]);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.168.10.0',
                'prefix_length' => 24,
                'core_switch_id' => $erisim->uuid,
            ])
            ->assertSessionHasErrors('core_switch_id');
    }

    public function test_erisim_alanina_omurga_switchi_secilemez(): void
    {
        $omurga = NetworkSwitch::factory()->create(['role' => SwitchRole::Core]);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.168.10.0',
                'prefix_length' => 24,
                'access_switch_id' => $omurga->uuid,
            ])
            ->assertSessionHasErrors('access_switch_id');
    }

    /**
     * @param  list<string>  $actions
     */
    /**
     * L3 arayüzünün birim numarası VLAN etiketine EŞİT DEĞİLDİR.
     *
     * Bir switchte örneğin V10 (etiket 10) vlan.9'u, V11 (etiket 11)
     * vlan.10'u kullanabilir. Panel arayüz adını "vlan.<etiket>"
     * diye kursaydı V10'a verilen geçit vlan.10'a — yani KOMŞU VLAN'ın
     * arayüzüne, başka bir müşteriye — yazılırdı.
     */
    public function test_vlan_gecidi_etiketten_degil_l3_arayuzunden_yazilir(): void
    {
        $core = $this->coreSwitchThatCanBeConfigured();
        $hedef = Vlan::factory()->for($core, 'switch')->create([
            'name' => 'V10', 'vlan_tag' => 10, 'l3_interface' => 'vlan.9',
        ]);
        // Etiketle aynı numarayı taşıyan KOMŞU VLAN; hedef bu olmamalı.
        Vlan::factory()->for($core, 'switch')->create([
            'name' => 'V11', 'vlan_tag' => 11, 'l3_interface' => 'vlan.10',
        ]);

        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '198.18.7.72',
                'prefix_length' => 29,
                'core_switch_id' => $core->uuid,
                'connection_type' => AddressConnectionType::Vlan->value,
                'vlan_id' => $hedef->uuid,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = $device->payload();

        $this->assertStringContainsString('<name>vlan</name>', $yazilan);
        $this->assertStringContainsString('<name>9</name>', $yazilan);
        $this->assertStringNotContainsString('<name>10</name>', $yazilan);
        $this->assertStringContainsString('198.18.7.73/29', $yazilan);
    }

    /**
     * L3 arayüzü olmayan VLAN geçit taşıyamaz: yazılacak arayüz adı yoktur.
     *
     * Adresleme ekranı da IP adresleri ekranı gibi reddeder; reddetmeseydi
     * blok panelde "bağlandı" görünür, cihazda hiçbir arayüz onu taşımazdı.
     */
    public function test_l3_arayuzu_olmayan_vlana_blok_baglanamaz(): void
    {
        $core = $this->coreSwitchThatCanBeConfigured();
        $vlan = Vlan::factory()->for($core, 'switch')->create([
            'name' => 'V27', 'vlan_tag' => 27, 'l3_interface' => null,
        ]);

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '192.168.40.0',
                'prefix_length' => 24,
                'core_switch_id' => $core->uuid,
                'connection_type' => AddressConnectionType::Vlan->value,
                'vlan_id' => $vlan->uuid,
            ])
            ->assertSessionHasErrors('vlan_id');

        $this->assertDatabaseMissing('address_blocks', ['network' => '192.168.40.0']);
    }

    /**
     * Etiketsiz VLAN da blok hedefi olabilir.
     *
     * Bağ VLAN kaydının kendisiyle kurulur, etiketiyle değil; etiketle
     * kurulsaydı etiketsiz VLAN, üstünde müşteri IP'si taşısa bile, hiç
     * seçilemezdi.
     */
    public function test_etiketsiz_vlana_blok_baglanabilir(): void
    {
        $core = $this->coreSwitchThatCanBeConfigured();
        $vlan = Vlan::factory()->for($core, 'switch')->create([
            'name' => 'V27', 'vlan_tag' => null, 'l3_interface' => 'vlan.28',
        ]);

        $device = $this->fakeDevice();

        $this->actingAs($this->operator())
            ->post(route('addressing.store'), [
                'network' => '198.18.7.208',
                'prefix_length' => 29,
                'core_switch_id' => $core->uuid,
                'connection_type' => AddressConnectionType::Vlan->value,
                'vlan_id' => $vlan->uuid,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = $device->payload();

        $this->assertStringContainsString('<name>vlan</name>', $yazilan);
        $this->assertStringContainsString('<name>28</name>', $yazilan);
        $this->assertStringContainsString('198.18.7.209/29', $yazilan);

        $blok = AddressBlock::query()->firstWhere('network', '198.18.7.208');

        $this->assertSame($vlan->id, $blok->vlan_id);
        $this->assertSame('VLAN V27', $blok->assignment_target);
    }

    /**
     * Bloğun bağlandığı VLAN sessizce silinemez.
     *
     * Bağ yabancı anahtar: VLAN gidince blok "atanmamış"a dönerdi ve
     * kullanıcı adresleme envanterindeki atamayı da bozduğunu görmezdi.
     */
    public function test_bloka_bagli_vlan_silinemez(): void
    {
        $core = $this->coreSwitchThatCanBeConfigured();
        $vlan = Vlan::factory()->for($core, 'switch')->create(['name' => 'V30', 'vlan_tag' => 30]);

        AddressBlock::factory()->network('198.19.0.0/24')->create([
            'core_switch_id' => $core->id,
            'connection_type' => AddressConnectionType::Vlan,
            'vlan_id' => $vlan->id,
        ]);

        $this->fakeDevice();

        $this->actingAs($this->operator())
            ->delete(route('switches.vlans.destroy', [$core, $vlan]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('vlans', ['id' => $vlan->id]);
    }

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
