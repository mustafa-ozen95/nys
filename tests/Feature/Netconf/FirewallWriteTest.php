<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Enums\FirewallAction;
use App\Models\NetworkSwitch;
use App\Models\SwitchFirewallFilter;
use App\Models\SwitchFirewallTerm;
use App\Models\SwitchPrefixList;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Firewall filtreleri.
 *
 * Panelin en tehlikeli yazma yolu: yanlış bir filtre bütün trafiği keser ve
 * RE filtresi söz konusuysa cihaza erişimi de koparır. Yetki bu yüzden
 * yalnızca yöneticidedir.
 */
final class FirewallWriteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Diğer nesnelere yazma yetkisi firewall için YETMEZ: port ya da VLAN
     * değiştirebilen biri, bütün trafiği kesebilecek biri değildir. Firewall
     * kendi yetkisini ister ve o yetki ayrıca verilir.
     */
    public function test_diger_nesne_yetkileri_firewall_icin_yetmez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operatorWithoutFirewall())
            ->post(route('switches.firewall.store', $switch), $this->payload())
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    public function test_yonetici_filtre_yazabilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->admin())
            ->post(route('switches.firewall.store', $switch), $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<firewall><family><inet>', $written);
        $this->assertStringContainsString('<filter><name>koruma</name>', $written);
        $this->assertStringContainsString('<source-address><name>10.0.0.0/8</name></source-address>', $written);
        $this->assertStringContainsString('<then><accept/></then>', $written);
    }

    /**
     * NETCONF kimliği olmayan switche yönetici de yazamaz: yazacak yol yoktur.
     */
    public function test_netconf_kimligi_yoksa_yonetici_de_yazamaz(): void
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.199']);
        $device = $this->device();

        $this->actingAs($this->admin())
            ->post(route('switches.firewall.store', $switch), $this->payload())
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    public function test_protokol_port_ve_icmp_kosullari_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->admin())
            ->post(route('switches.firewall.store', $switch), [
                'filter_name' => 'RE_FILTER',
                'family' => 'inet',
                'name' => 'bgp',
                'source_addresses' => '198.18.8.36/30',
                'protocols' => ['tcp'],
                'ports' => '179',
                'action' => 'accept',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<protocol>tcp</protocol>', $written);
        $this->assertStringContainsString('<port>179</port>', $written);
    }

    public function test_sayac_ve_gunluk_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->admin())
            ->post(route('switches.firewall.store', $switch), [
                'filter_name' => 'sayan',
                'family' => 'inet',
                'name' => 't',
                'action' => 'discard',
                'count_name' => 'dusenler',
                'is_logged' => '1',
            ])
            ->assertRedirect();

        $written = $device->payload();

        $this->assertStringContainsString('<count>dusenler</count>', $written);
        $this->assertStringContainsString('<log/>', $written);
        $this->assertStringContainsString('<discard/>', $written);
    }

    /**
     * Tanımsız prefix list'e atıf commit'i düşürür.
     */
    public function test_cihazda_olmayan_prefix_list_secilemez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->admin())
            ->post(route('switches.firewall.store', $switch), [
                'filter_name' => 'koruma',
                'family' => 'inet',
                'name' => 'list',
                'source_prefix_lists' => ['olmayan'],
                'action' => 'accept',
            ])
            ->assertSessionHasErrors('source_prefix_lists.0');

        $this->assertNull($device->connection);
    }

    /**
     * Terim SIRASI anlamlıdır ve merge yeni terimi hep sona ekler; filtre
     * silinip bütün terimleriyle yeniden yazılır.
     */
    public function test_terim_eklerken_filtre_silinip_yeniden_yazilir(): void
    {
        $switch = $this->switch();
        $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')->create(['name' => 'koruma']);
        SwitchFirewallTerm::factory()->for($filter, 'filter')->create(['name' => 'list', 'position' => 0]);
        $device = $this->device();

        $this->actingAs($this->admin())
            ->post(route('switches.firewall.terms.store', [$switch, $filter]), [
                'name' => 'deny', 'action' => 'discard',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<filter delete="delete"><name>koruma</name>', $written);
        $this->assertTrue(strpos($written, '<name>list</name>') < strpos($written, '<name>deny</name>'));
    }

    public function test_son_terim_silinemez(): void
    {
        $switch = $this->switch();
        $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')->create();
        $term = SwitchFirewallTerm::factory()->for($filter, 'filter')->create();
        $device = $this->device();

        $this->actingAs($this->admin())
            ->delete(route('switches.firewall.terms.destroy', [$switch, $filter, $term]))
            ->assertStatus(422);

        $this->assertNull($device->connection);
    }

    /**
     * Panelin göstermediği satırı olan filtre SALT OKUNUR DEĞİLDİR.
     *
     * Satır ham hâliyle saklanıp geri yazılır; düzenleme yolu açıktır.
     * Okuyucu böyle bir filtreyi is_manageable = false yapsaydı kullanıcı onu
     * hiç yönetemezdi.
     */
    public function test_korunan_satiri_olan_filtreye_terim_eklenebilir(): void
    {
        $switch = $this->switch();
        $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')
            ->withPreservedLines()->create(['name' => 'koruma']);
        SwitchFirewallTerm::factory()->for($filter, 'filter')->create(['name' => 'list', 'position' => 0]);
        $device = $this->device();

        $this->actingAs($this->admin())
            ->post(route('switches.firewall.terms.store', [$switch, $filter]), ['name' => 'x', 'action' => 'accept'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<uydurma-satir>x</uydurma-satir>', $device->payload());
    }

    /**
     * Ekranı görmek yazma yetkisi gerektirmez; DEĞİŞTİRMEK gerektirir.
     */
    public function test_ekran_goruntuleme_yetkisiyle_acilir(): void
    {
        $switch = $this->switch();
        SwitchFirewallFilter::factory()->for($switch, 'switch')->bound()->create(['name' => 'koruma']);

        $this->actingAs($this->operatorWithoutFirewall())
            ->get(route('switches.firewall', $switch))
            ->assertOk()
            ->assertSee('koruma')
            ->assertSee('değiştirme yetkiniz yok', escape: false);
    }

    public function test_baska_switchin_filtresi_kullanilamaz(): void
    {
        $switch = $this->switch();
        $other = $this->switch('198.18.20.196');
        $foreign = SwitchFirewallFilter::factory()->for($other, 'switch')->create();
        $device = $this->device();

        $this->actingAs($this->admin())
            ->delete(route('switches.firewall.destroy', [$switch, $foreign]))
            ->assertNotFound();

        $this->assertNull($device->connection);
    }

    public function test_terim_yerinde_guncellenir(): void
    {
        $switch = $this->switch();
        $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')->create(['name' => 'koruma']);
        SwitchFirewallTerm::factory()->for($filter, 'filter')->create(['name' => 'list', 'position' => 0]);
        $last = SwitchFirewallTerm::factory()->for($filter, 'filter')
            ->create(['name' => 'deny', 'position' => 1, 'action' => FirewallAction::Discard]);
        $device = $this->device();

        $this->actingAs($this->admin())
            ->put(route('switches.firewall.terms.update', [$switch, $filter, $last]), [
                'name' => 'deny',
                'action' => 'reject',
                'position' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        // Aynı terim iki kez yazılmaz ve sıra korunur.
        $this->assertSame(1, substr_count($written, '<name>deny</name>'));
        $this->assertStringContainsString('<reject/>', $written);
        $this->assertTrue(strpos($written, '<name>list</name>') < strpos($written, '<name>deny</name>'));
    }

    /**
     * Junos'ta İLK EŞLEŞEN terim kazanır: bir terimi "her şeyi düşür"ün önüne
     * almanın tek yolu sırayı değiştirmektir.
     */
    public function test_terim_sirasi_degistirilebilir(): void
    {
        $switch = $this->switch();
        $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')->create(['name' => 'koruma']);
        SwitchFirewallTerm::factory()->for($filter, 'filter')
            ->create(['name' => 'deny', 'position' => 0, 'action' => FirewallAction::Discard]);
        $accept = SwitchFirewallTerm::factory()->for($filter, 'filter')
            ->create(['name' => 'kabul', 'position' => 1]);
        $device = $this->device();

        $this->actingAs($this->admin())
            ->put(route('switches.firewall.terms.update', [$switch, $filter, $accept]), [
                'name' => 'kabul',
                'action' => 'accept',
                'position' => 0,
            ])
            ->assertRedirect();

        $written = $device->payload();

        $this->assertTrue(strpos($written, '<name>kabul</name>') < strpos($written, '<name>deny</name>'));
    }

    /**
     * Adı değişen terimin eskisi listede kalmamalı.
     */
    public function test_adi_degisen_terim_ikizlenmez(): void
    {
        $switch = $this->switch();
        $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')->create();
        $term = SwitchFirewallTerm::factory()->for($filter, 'filter')->create(['name' => 'eski', 'position' => 0]);
        $device = $this->device();

        $this->actingAs($this->admin())
            ->put(route('switches.firewall.terms.update', [$switch, $filter, $term]), [
                'name' => 'yeni', 'action' => 'accept',
            ])
            ->assertRedirect();

        $written = $device->payload();

        $this->assertStringContainsString('<name>yeni</name>', $written);
        // "eski" yalnızca filtre silme satırında geçebilir, terim olarak değil.
        $this->assertStringNotContainsString('<term><name>eski</name>', $written);
    }

    public function test_terim_guncellemek_firewall_yetkisi_ister(): void
    {
        $switch = $this->switch();
        $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')->create();
        $term = SwitchFirewallTerm::factory()->for($filter, 'filter')->create();
        $device = $this->device();

        $this->actingAs($this->operatorWithoutFirewall())
            ->put(route('switches.firewall.terms.update', [$switch, $filter, $term]), [
                'name' => 'x', 'action' => 'accept',
            ])
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    /**
     * Sürükle-bırak sıralaması: gelen liste cihaza aynen yazılır.
     */
    public function test_terimler_verilen_siraya_dizilir(): void
    {
        $switch = $this->switch();
        $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')->create(['name' => 'koruma']);
        $first = SwitchFirewallTerm::factory()->for($filter, 'filter')->create(['name' => 'deny', 'position' => 0]);
        $second = SwitchFirewallTerm::factory()->for($filter, 'filter')->create(['name' => 'kabul', 'position' => 1]);
        $device = $this->device();

        $this->actingAs($this->admin())
            ->put(route('switches.firewall.terms.order', [$switch, $filter]), [
                'terms' => [$second->uuid, $first->uuid],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertTrue(strpos($written, '<name>kabul</name>') < strpos($written, '<name>deny</name>'));
    }

    /**
     * Eksik liste, adı geçmeyen terimi sessizce silerdi; bir firewall
     * filtresinde bunun bedeli kesintidir.
     */
    public function test_eksik_siralama_listesi_reddedilir(): void
    {
        $switch = $this->switch();
        $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')->create();
        $first = SwitchFirewallTerm::factory()->for($filter, 'filter')->create(['position' => 0]);
        SwitchFirewallTerm::factory()->for($filter, 'filter')->create(['position' => 1]);
        $device = $this->device();

        $this->actingAs($this->admin())
            ->put(route('switches.firewall.terms.order', [$switch, $filter]), ['terms' => [$first->uuid]])
            ->assertStatus(422);

        $this->assertNull($device->connection);
    }

    /**
     * Başka filtrenin terimi sıralamaya sokulamaz.
     */
    public function test_yabanci_terim_siralamaya_giremez(): void
    {
        $switch = $this->switch();
        $filter = SwitchFirewallFilter::factory()->for($switch, 'switch')->create();
        SwitchFirewallTerm::factory()->for($filter, 'filter')->create(['position' => 0]);

        $other = SwitchFirewallFilter::factory()->for($switch, 'switch')->create();
        $foreign = SwitchFirewallTerm::factory()->for($other, 'filter')->create();
        $device = $this->device();

        $this->actingAs($this->admin())
            ->put(route('switches.firewall.terms.order', [$switch, $filter]), ['terms' => [$foreign->uuid]])
            ->assertStatus(422);

        $this->assertNull($device->connection);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'filter_name' => 'koruma',
            'family' => 'inet',
            'name' => 'kabul',
            'source_addresses' => '10.0.0.0/8',
            'action' => FirewallAction::Accept->value,
        ];
    }

    private function switch(string $ip = '198.18.20.195'): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => $ip]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        SwitchPrefixList::factory()->for($switch, 'switch')->create(['name' => 'koru']);

        return $switch->refresh();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }

    /**
     * Firewall DIŞINDA her cihaz nesnesine yazabilen kullanıcı.
     *
     * "Bir nesneye yazabilmek diğerine yazabilmeyi getirmez" kuralını
     * sınayan testlerin dayanağı budur.
     */
    private function operatorWithoutFirewall(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_firewall', ['view']);

        return $user->fresh();
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
