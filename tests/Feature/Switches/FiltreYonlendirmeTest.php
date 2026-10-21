<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\JunosSchemaProfile;
use App\Models\NetworkSwitch;
use App\Models\SwitchRibGroup;
use App\Models\SwitchRoutingInstance;
use App\Models\User;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Filtre tabanlı yönlendirme (FBF).
 *
 * Üç parçadan oluşur ve üçü olmadan çalışmaz. En çok atlanan parça RIB
 * grubunun BAĞLANMASI: arayüz yolları yalnızca inet.0'dadır, kopyalanmazsa
 * hedef tablo kendi arayüzlerine bile ulaşamaz ve trafik sessizce düşer —
 * cihaz hata VERMEZ. Ekranın asıl işi bunu söylemek.
 */
final class FiltreYonlendirmeTest extends TestCase
{
    use RefreshDatabase;

    public function test_eksik_parca_ekranda_soylenir(): void
    {
        $switch = $this->switch();

        $this->actingAs($this->operator())
            ->get(route('switches.forwarding', $switch))
            ->assertOk()
            ->assertSee('Yönlendirme tablosu yok')
            ->assertSee('RIB grubu yok');
    }

    /**
     * Grup VAR ama BAĞLI DEĞİL: en sinsi durum, çünkü her şey kurulmuş
     * görünür ve cihaz hata vermez.
     */
    public function test_baglanmamis_grup_uyari_uretir(): void
    {
        $switch = $this->switch();
        SwitchRoutingInstance::factory()->for($switch, 'switch')->create(['name' => 'musteri']);
        SwitchRibGroup::factory()->for($switch, 'switch')->create(['name' => 'musteri-yollari']);

        $this->actingAs($this->operator())
            ->get(route('switches.forwarding', $switch))
            ->assertOk()
            ->assertSee('BAĞLI DEĞİL', false);
    }

    public function test_tablo_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.forwarding.instances.store', $switch), [
                'name' => 'musteri',
                'instance_type' => 'forwarding',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<name>musteri</name>', $payload);
        $this->assertStringContainsString('<instance-type>forwarding</instance-type>', $payload);
    }

    /**
     * TÜR CİHAZA GÖRE daralır: EX2300 "forwarding"i reddediyor ve o cihazda
     * FBF virtual-router ile kurulmak zorunda (test4'te ölçüldü). Panel
     * cihazın kabul etmediği türü SUNMAZ ve elle gönderileni reddeder.
     */
    public function test_cihazin_kabul_etmedigi_tur_reddedilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill([
            'schema_profile' => JunosSchemaProfile::Els,
            'device_capabilities' => ['routing.instance_types' => ['virtual-router', 'no-forwarding']],
        ])->save();

        $device = $this->device();

        $this->actingAs($this->operator())
            ->get(route('switches.forwarding', $switch))
            ->assertOk()
            ->assertDontSee('Yönlendirme tablosu (forwarding)');

        $this->actingAs($this->operator())
            ->post(route('switches.forwarding.instances.store', $switch), [
                'name' => 'musteri',
                'instance_type' => 'forwarding',
            ])
            ->assertSessionHasErrors('instance_type');

        $this->assertNull($device->connection, 'Reddedilen tür cihaza gitmemeliydi.');
    }

    public function test_rib_grubu_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.forwarding.groups.store', $switch), [
                'name' => 'musteri-yollari',
                'import_ribs' => "inet.0\nmusteri.inet.0",
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<import-rib>inet.0</import-rib>', $payload);
        $this->assertStringContainsString('<import-rib>musteri.inet.0</import-rib>', $payload);
    }

    /**
     * BOŞ grup cihazda kabul edilse de hiçbir işe yaramaz; panel önce söyler.
     */
    public function test_bos_grup_kabul_edilmez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.forwarding.groups.store', $switch), [
                'name' => 'bos-grup',
                'import_ribs' => '',
            ])
            ->assertSessionHasErrors('import_ribs');

        $this->assertNull($device->connection);
    }

    public function test_arayuz_yollari_gruba_baglanir(): void
    {
        $switch = $this->switch();
        SwitchRibGroup::factory()->for($switch, 'switch')->create(['name' => 'musteri-yollari']);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.forwarding.bind', $switch), ['rib_group' => 'musteri-yollari'])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('musteri-yollari', $device->payload());
    }

    public function test_yetkisiz_kullanici_yazamaz(): void
    {
        $switch = $this->switch();

        $user = User::factory()->create();

        $this->actingAs($user->fresh())
            ->post(route('switches.forwarding.instances.store', $switch), [
                'name' => 'musteri',
                'instance_type' => 'forwarding',
            ])
            ->assertForbidden();
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice('[edit routing-instances]');
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
        return User::factory()->admin()->create()->fresh();
    }
}
