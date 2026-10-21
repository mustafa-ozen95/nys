<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\SwitchStormControl;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Fırtına denetimi panelden yönetilir.
 *
 * Yayın fırtınası bir anahtarı dakikalar içinde kullanılamaz hâle getirir ve
 * bu ayar bugüne kadar hiç yönetilmiyordu — cihazda vardı, panelde yoktu.
 *
 * Ayarın YERİ şema ailesine bağlıdır ve ikisi birbirinin yerine geçmez;
 * test1'de ölçüldü: ELS biçimi ELS öncesi cihazda "syntax error" veriyor.
 */
final class StormControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_yuzde_esigi_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.rstp.storm.store', $switch), [
                'interface_name' => 'all',
                'level' => 80,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<ethernet-switching-options><storm-control>', $payload);
        $this->assertStringContainsString('<name>all</name><level>80</level>', $payload);
    }

    public function test_kbps_esigi_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.rstp.storm.store', $switch), [
                'interface_name' => 'all',
                'bandwidth' => 10000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<bandwidth>10000</bandwidth>', $device->payload());
    }

    /**
     * Eşik ya yüzde ya kbps'dir; cihaz ikisini birlikte kabul etmez.
     */
    public function test_iki_esik_birlikte_verilemez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.rstp.storm.store', $switch), [
                'interface_name' => 'all',
                'level' => 80,
                'bandwidth' => 10000,
            ])
            ->assertSessionHasErrors('level');

        $this->assertNull($device->connection);
    }

    /**
     * Cihaz, anahtarlama ailesinde olmayan porta fırtına denetimi vermiyor:
     * "Interface ge-0/0/20.0 not found" (test1'de ölçüldü). Panel önce söyler.
     */
    public function test_anahtarlama_disi_porta_verilemez(): void
    {
        $switch = $this->switch();
        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/9',
            'port_mode' => PortMode::Routed,
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.rstp.storm.store', $switch), [
                'interface_name' => 'ge-0/0/9',
                'level' => 80,
            ])
            ->assertSessionHasErrors('interface_name');

        $this->assertNull($device->connection);
    }

    /**
     * ELS'te ayar başka yerde durur: profil tanımlanır ve arayüze bağlanır.
     * ELS öncesi biçim orada "syntax error" verir, tersi de doğrudur.
     */
    public function test_els_cihazda_profil_biciminde_yazilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();
        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/9',
            'port_mode' => PortMode::Access,
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.rstp.storm.store', $switch), [
                'interface_name' => 'ge-0/0/9',
                'level' => 80,
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<storm-control-profiles', $payload);
        $this->assertStringNotContainsString('<ethernet-switching-options>', $payload);
    }

    /**
     * ELS "all" sözde-adını reddediyor: "invalid interface type in 'all'"
     * (test4 / EX2300 24.4R2-S4). Panel cihaza gitmeden söylüyor.
     */
    public function test_els_cihazda_all_kisayolu_reddedilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.rstp.storm.store', $switch), [
                'interface_name' => 'all',
                'level' => 80,
            ])
            ->assertSessionHasErrors('interface_name');

        $this->assertNull($device->connection, 'Reddedilen istek cihaza gitmemeliydi.');
    }

    /**
     * Reddedilen kısayol FIRTINA listesinde sunulmaz; yoksa kullanıcı
     * seçemeyeceği bir seçeneği seçip hata yer.
     *
     * Aynı ekranda SPANNING TREE listesi kısayolu KORUR: "protocols rstp
     * interface all" iki lehçede de kabul ediliyor (test2 ve test4'te
     * ölçüldü). İki liste bu yüzden ayrıdır; tek liste paylaşılsaydı biri
     * için doğru olan öbürüne yanlış yazılırdı.
     */
    public function test_els_ekraninda_firtina_listesi_all_sunmaz(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();

        $response = $this->actingAs($this->operator())
            ->get(route('switches.rstp', $switch))
            ->assertOk();

        $this->assertArrayNotHasKey('all', $response->viewData('stormInterfaceOptions'));
        $this->assertArrayHasKey('all', $response->viewData('interfaceOptions'));
    }

    /**
     * ELS ÖNCESİNDE kısayol her iki listede de geçerlidir (test1/test2'de
     * ölçüldü).
     */
    public function test_els_oncesi_ekraninda_iki_liste_de_all_sunar(): void
    {
        $switch = $this->switch();

        $response = $this->actingAs($this->operator())
            ->get(route('switches.rstp', $switch))
            ->assertOk();

        $this->assertArrayHasKey('all', $response->viewData('stormInterfaceOptions'));
        $this->assertArrayHasKey('all', $response->viewData('interfaceOptions'));
    }

    /**
     * "Eşik aşılınca kapat" ELS'te switch genelinde DEĞİL, her profilin
     * içinde yaşar (test4'te ölçüldü). Bu yol lehçeyi hiç sormuyordu ve
     * ELS'te "syntax error" alıyordu.
     */
    public function test_els_cihazda_kapatma_profillere_yazilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();

        SwitchStormControl::factory()->for($switch, 'switch')->create([
            'interface_name' => 'ge-0/0/9',
            'level' => 80,
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.rstp.storm.shutdown', $switch), ['storm_control_shutdown' => '1'])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<storm-control-profiles><name>panel-ge-0-0-9</name>', $payload);
        $this->assertStringContainsString('<action-shutdown/>', $payload);

        // Geri okuma iki kökü de sorar; YAZILAN belgede eski biçim olmamalı.
        $this->assertStringNotContainsString(
            '<ethernet-switching-options><storm-control>',
            $payload,
        );
    }

    /**
     * Profil "replace" ile yazılıyor: arayüz düzenlemesi kapatma bayrağını
     * SESSİZCE silmemeli.
     */
    public function test_els_cihazda_arayuz_yazimi_kapatmayi_silmez(): void
    {
        $switch = $this->switch();
        $switch->forceFill([
            'schema_profile' => JunosSchemaProfile::Els,
            'storm_control_shutdown' => true,
        ])->save();

        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/9',
            'port_mode' => PortMode::Access,
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.rstp.storm.store', $switch), [
                'interface_name' => 'ge-0/0/9',
                'level' => 80,
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<action-shutdown/>', $device->payload());
    }

    /**
     * ELS ÖNCESİNDE ayar switch genelinde tek satırdır ve orada kalır.
     */
    public function test_els_oncesi_kapatma_switch_genelinde_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.rstp.storm.shutdown', $switch), ['storm_control_shutdown' => '1'])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString(
            '<ethernet-switching-options><storm-control><action-shutdown/>',
            $payload,
        );
        $this->assertStringNotContainsString('storm-control-profiles', $payload);
    }

    /**
     * Panelin göstermediği satır düzenlemede KAYBOLMAZ.
     */
    public function test_korunan_ham_satir_geri_yazilir(): void
    {
        $switch = $this->switch();

        $storm = SwitchStormControl::factory()->for($switch, 'switch')->create([
            'interface_name' => 'all',
            'level' => 80,
            'residual_xml' => ['<uydurma-alan/>'],
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.rstp.storm.update', [$switch, $storm]), ['level' => 60])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<level>60</level>', $payload);
        $this->assertStringContainsString('<uydurma-alan/>', $payload, 'Korunan satır geri yazılmadı.');
    }

    public function test_yetkisiz_kullanici_yazamaz(): void
    {
        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        $this->actingAs($user->fresh())
            ->post(route('switches.rstp.storm.store', $switch), ['interface_name' => 'all', 'level' => 80])
            ->assertForbidden();
    }

    public function test_ekran_acilir(): void
    {
        $switch = $this->switch();
        SwitchStormControl::factory()->for($switch, 'switch')->create(['interface_name' => 'all', 'level' => 80]);

        $this->actingAs($this->operator())
            ->get(route('switches.rstp', $switch))
            ->assertOk()
            ->assertSee('Fırtına denetimi')
            ->assertSee('%80');
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice('[edit ethernet-switching-options]');
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
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
