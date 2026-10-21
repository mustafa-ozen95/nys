<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\SwitchVoipInterface;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * VoIP (ses) VLAN'ı panelden yönetilir.
 *
 * IP telefon ile ona bağlı bilgisayar tek kabloyu paylaşır: telefon ses
 * VLAN'ında etiketli, bilgisayar portun kendi VLAN'ında etiketsiz gider.
 * Ayar cihazda vardı, panelde hiç yönetilmiyordu.
 *
 * Şema test1'de (12.3R12.4) commit check ile doğrulandı; cihaz yapıyı KABUL
 * etti ve reddetme yalnızca semantikti: "Interface ge-0/0/20.0 not found or
 * does not have ethernet-switching family".
 */
final class VoipTest extends TestCase
{
    use RefreshDatabase;

    public function test_ses_vlani_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $this->accessPort($switch, 'ge-0/0/5', 'ofis');
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ses', 'vlan_tag' => 200]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.voip.store', $switch), [
                'interface_name' => 'ge-0/0/5.0',
                'vlan_name' => 'ses',
                'forwarding_class' => 'expedited-forwarding',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<ethernet-switching-options><voip>', $payload);
        $this->assertStringContainsString(
            '<interface replace="replace"><name>ge-0/0/5.0</name><vlan>ses</vlan>'
                .'<forwarding-class>expedited-forwarding</forwarding-class></interface>',
            $payload,
        );
    }

    /**
     * Junos arayüzü BİRİMİYLE yazar (ge-0/0/5.0) ve cihazdan da öyle okunur.
     * Birimsiz gelen ad tamamlanmasaydı aynı arayüz iki ayrı kayıt olur ve
     * tekillik kuralı hiçbir şeyi korumazdı.
     */
    public function test_birimsiz_arayuz_adi_tamamlanir(): void
    {
        $switch = $this->switch();
        $this->accessPort($switch, 'ge-0/0/5', 'ofis');
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ses', 'vlan_tag' => 200]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.voip.store', $switch), [
                'interface_name' => 'ge-0/0/5',
                'vlan_name' => 'ses',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<name>ge-0/0/5.0</name>', $device->payload());
    }

    /**
     * Cihazda VLAN'ın kimliği ADIDIR; tanımsız ada atıf commit'i düşürür.
     */
    public function test_switchte_tanimsiz_vlan_reddedilir(): void
    {
        $switch = $this->switch();
        $this->accessPort($switch, 'ge-0/0/5', 'ofis');

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.voip.store', $switch), [
                'interface_name' => 'ge-0/0/5.0',
                'vlan_name' => 'olmayan',
            ])
            ->assertSessionHasErrors('vlan_name');

        $this->assertNull($device->connection);
    }

    /**
     * Cihaz, anahtarlama ailesinde olmayan porta ses VLAN'ı vermiyor:
     * "Interface ge-0/0/20.0 not found or does not have ethernet-switching
     * family" (test1'de ölçüldü). Panel bunu formda önceden söyler.
     */
    public function test_anahtarlama_disi_porta_verilemez(): void
    {
        $switch = $this->switch();
        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/9',
            'port_mode' => PortMode::Routed,
        ]);
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ses', 'vlan_tag' => 200]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.voip.store', $switch), [
                'interface_name' => 'ge-0/0/9.0',
                'vlan_name' => 'ses',
            ])
            ->assertSessionHasErrors('interface_name');

        $this->assertNull($device->connection);
    }

    /**
     * Ses VLAN'ı portun ETİKETSİZ VLAN'ından farklı olmalıdır: telefon
     * etiketli, bilgisayar etiketsiz gider. Aynısı seçilirse ayarın hiçbir
     * etkisi olmaz.
     */
    public function test_portun_etiketsiz_vlani_ses_vlani_olamaz(): void
    {
        $switch = $this->switch();
        $this->accessPort($switch, 'ge-0/0/5', 'ofis');

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.voip.store', $switch), [
                'interface_name' => 'ge-0/0/5.0',
                'vlan_name' => 'ofis',
            ])
            ->assertSessionHasErrors('vlan_name');

        $this->assertNull($device->connection);
    }

    /**
     * Cihazdan okunmuş çakışık bir kayıt panelden DÜZENLENEBİLİR kalmalı:
     * kural yalnızca değeri değiştirene uygulanır, yoksa bozuk satır hiç
     * düzeltilemezdi.
     */
    public function test_cihazdan_gelen_cakisik_kayit_kilitlenmez(): void
    {
        $switch = $this->switch();
        $this->accessPort($switch, 'ge-0/0/5', 'ofis');

        $voip = SwitchVoipInterface::factory()->for($switch, 'switch')->create([
            'interface_name' => 'ge-0/0/5.0',
            'vlan_name' => 'ofis',
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.voip.update', [$switch, $voip]), [
                'vlan_name' => 'ofis',
                'forwarding_class' => 'expedited-forwarding',
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<forwarding-class>expedited-forwarding</forwarding-class>', $device->payload());
    }

    /**
     * ELS'te aynı ayar switch-options altındadır ve iki kapsayıcı birbirinin
     * yerine geçmez.
     */
    public function test_els_cihazda_switch_options_altina_yazilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ses', 'vlan_tag' => 200]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.voip.store', $switch), [
                'interface_name' => 'access-ports',
                'vlan_name' => 'ses',
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<switch-options><voip>', $payload);
        $this->assertStringNotContainsString('<ethernet-switching-options>', $payload);
    }

    /**
     * Panelin göstermediği satır düzenlemede KAYBOLMAZ.
     */
    public function test_korunan_ham_satir_geri_yazilir(): void
    {
        $switch = $this->switch();
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ses', 'vlan_tag' => 200]);

        $voip = SwitchVoipInterface::factory()->for($switch, 'switch')->create([
            'interface_name' => 'access-ports',
            'vlan_name' => 'ses',
            'residual_xml' => ['<uydurma-alan/>'],
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.voip.update', [$switch, $voip]), ['vlan_name' => 'ses'])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<uydurma-alan/>', $device->payload(), 'Korunan satır geri yazılmadı.');
    }

    /**
     * Arayüz adı cihazda KİMLİKTİR: değişmiş bir değer sessizce yok
     * sayılmaz, açıkça reddedilir.
     */
    public function test_arayuz_adi_duzenlemede_degistirilemez(): void
    {
        $switch = $this->switch();
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ses', 'vlan_tag' => 200]);

        $voip = SwitchVoipInterface::factory()->for($switch, 'switch')->create([
            'interface_name' => 'access-ports',
            'vlan_name' => 'ses',
        ]);

        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'port_mode' => PortMode::Access,
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.voip.update', [$switch, $voip]), [
                'interface_name' => 'ge-0/0/5.0',
                'vlan_name' => 'ses',
            ])
            ->assertSessionHasErrors('interface_name');

        $this->assertNull($device->connection);
    }

    public function test_ayar_cihazdan_silinir(): void
    {
        $switch = $this->switch();

        $voip = SwitchVoipInterface::factory()->for($switch, 'switch')->create([
            'interface_name' => 'ge-0/0/5.0',
            'vlan_name' => 'ses',
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->delete(route('switches.voip.destroy', [$switch, $voip]))
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<interface delete="delete"><name>ge-0/0/5.0</name></interface>',
            $device->payload(),
        );
    }

    /**
     * Boş bir arayüz düğümü cihazda hiçbir işe yaramaz.
     */
    public function test_bos_ayar_kabul_edilmez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.voip.store', $switch), ['interface_name' => 'access-ports'])
            ->assertSessionHasErrors('vlan_name');

        $this->assertNull($device->connection);
    }

    /**
     * Cihaz VoIP'te "all" adını KABUL ETMİYOR — ne ELS'te ne öncesinde
     * (aynı belge, tek fark arayüz adı):
     *
     *   test2 / 12.3 → "Interface all not found or does not have
     *                   ethernet-switching family"
     *   test4 / 24.4 → "ethernet-switching is not enabled on all!"
     *
     * Panel bunu uzun süre sundu; doğrulama senaryosu "access-ports"
     * kullandığı için hata görünmedi. Ekranın SUNDUĞU ad ölçülür.
     */
    public function test_cihazin_reddettigi_all_adi_sunulmaz(): void
    {
        $switch = $this->switch();

        $this->actingAs($this->operator())
            ->get(route('switches.voip', $switch))
            ->assertOk()
            ->assertSee('access-ports')
            ->assertDontSee('Bütün portlar (all)');
    }

    public function test_cihazin_reddettigi_all_adi_yazilamaz(): void
    {
        $switch = $this->switch();
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ses', 'vlan_tag' => 200]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.voip.store', $switch), [
                'interface_name' => 'all',
                'vlan_name' => 'ses',
            ])
            ->assertSessionHasErrors('interface_name');

        $this->assertNull($device->connection, 'Reddedilen ad cihaza gitmemeliydi.');
    }

    public function test_ekran_acilir(): void
    {
        $switch = $this->switch();
        $this->accessPort($switch, 'ge-0/0/5', 'ofis');
        SwitchVoipInterface::factory()->for($switch, 'switch')->create([
            'interface_name' => 'ge-0/0/5.0',
            'vlan_name' => 'ses',
            'residual_xml' => ['<uydurma-alan/>'],
        ]);

        $this->actingAs($this->operator())
            ->get(route('switches.voip', $switch))
            ->assertOk()
            ->assertSee('ge-0/0/5.0')
            ->assertSee('1 korunan satır');
    }

    /**
     * Ayarı zaten olan arayüz EKLEME listesinde durmaz: aynı arayüz iki
     * kayıt olamaz ve kullanıcıya seçtirip sonra "bu alınmış" demek işe
     * yaramaz. Kural sunucuda da vardır (tekillik).
     */
    public function test_ayari_olan_arayuz_ekleme_listesinde_durmaz(): void
    {
        $switch = $this->switch();
        $this->accessPort($switch, 'ge-0/0/5', 'ofis');
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ses', 'vlan_tag' => 200]);

        SwitchVoipInterface::factory()->for($switch, 'switch')->create([
            'interface_name' => 'ge-0/0/5.0',
            'vlan_name' => 'ses',
        ]);

        $this->actingAs($this->operator())
            ->get(route('switches.voip', $switch))
            ->assertOk()
            ->assertDontSee('<option value="ge-0/0/5.0"', false);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.voip.store', $switch), [
                'interface_name' => 'ge-0/0/5.0',
                'vlan_name' => 'ses',
            ])
            ->assertSessionHasErrors('interface_name');

        $this->assertNull($device->connection);
    }

    /**
     * Arayüzde düğmeyi gizlemek koruma değildir; istek doğrudan da gelebilir.
     */
    public function test_yetkisiz_kullanici_yazamaz(): void
    {
        $switch = $this->switch();
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ses', 'vlan_tag' => 200]);

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);
        $user = $user->fresh();

        // Ekranı görebilir...
        $this->actingAs($user)->get(route('switches.voip', $switch))->assertOk();

        // ...ama cihaza yazamaz.
        $this->actingAs($user)
            ->post(route('switches.voip.store', $switch), ['interface_name' => 'access-ports', 'vlan_name' => 'ses'])
            ->assertForbidden();
    }

    /**
     * Görüntüleme yetkisi de ayrıdır: verilmediyse ekran hiç açılmaz.
     */
    public function test_goremeyen_kullanici_ekrani_acamaz(): void
    {
        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_ports', ['view']);

        $this->actingAs($user->fresh())
            ->get(route('switches.voip', $switch))
            ->assertForbidden();
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice('[edit ethernet-switching-options]');
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    /**
     * Etiketsiz (access) VLAN'ı olan bir port. Ses VLAN'ı ondan farklı
     * olmak zorundadır.
     */
    private function accessPort(NetworkSwitch $switch, string $name, string $vlanName): SwitchPort
    {
        $port = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => $name,
            'port_mode' => PortMode::Access,
        ]);

        $vlan = Vlan::factory()->for($switch, 'switch')->create(['name' => $vlanName, 'vlan_tag' => 100]);
        $port->vlans()->attach($vlan, ['is_untagged' => true]);

        return $port;
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

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }
}
