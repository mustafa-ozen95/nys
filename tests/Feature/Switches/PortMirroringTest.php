<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Models\NetworkSwitch;
use App\Models\SwitchAnalyzer;
use App\Models\SwitchPort;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Port aynalama (analyzer) panelden yönetilir.
 *
 * Aynalama bugüne kadar hiç yönetilmiyordu: cihazda vardı, panelde yoktu.
 * Bir portun bütün trafiğini başka bir porta kopyalayan bir oturumun
 * envanterde görünmemesi, kimsenin fark etmediği bir dinleme demektir.
 *
 * Cihazın kendi kuralları formda ÖNCEDEN söylenir; testler bunu kilitler.
 */
final class PortMirroringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ELS'te aynalama BAŞKA KAPSAYICIDA ve çıkışı BAŞKA BİÇİMDE durur.
     *
     *   ELS öncesi  ethernet-switching-options analyzer
     *               <output><interface><name>X</name></interface></output>
     *   ELS         forwarding-options analyzer
     *               <output><interface>X</interface></output>
     *
     * İkisi de test4'te (EX2300-24T, 24.4R2-S4) ölçüldü: ELS cihazda ELS
     * öncesi biçim "syntax error" veriyor. Panel kapsayıcıyı hiç ayırmıyordu,
     * bu yüzden ELS bir switchte port aynalama HİÇ yazılamıyordu.
     *
     * GİRİŞ tarafı ikisinde de sarmalanmıştır; yalnızca çıkış farklı.
     */
    public function test_els_cihazda_aynalama_forwarding_options_altina_yazilir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.mirroring.store', $switch), [
                'name' => 'izleme',
                'ingress_interfaces' => ['ge-0/0/5.0'],
                'output_interface' => 'ge-0/0/21.0',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString('<forwarding-options><analyzer replace="replace">', $payload);
        $this->assertStringNotContainsString('<ethernet-switching-options>', $payload);

        // Çıkış düz metin, giriş sarmalanmış.
        $this->assertStringContainsString('<output><interface>ge-0/0/21.0</interface></output>', $payload);
        $this->assertStringContainsString('<ingress><interface><name>ge-0/0/5.0</name></interface></ingress>', $payload);
    }

    public function test_aynalama_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.mirroring.store', $switch), [
                'name' => 'izleme',
                'ingress_interfaces' => ['ge-0/0/5.0'],
                'egress_interfaces' => ['ge-0/0/5.0'],
                'output_interface' => 'ge-0/0/21.0',
                'ratio' => 2,
                'loss_priority' => 'low',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payload = $device->payload();

        $this->assertStringContainsString(
            '<ethernet-switching-options><analyzer replace="replace"><name>izleme</name>',
            $payload,
        );
        $this->assertStringContainsString('<ratio>2</ratio><loss-priority>low</loss-priority>', $payload);
        $this->assertStringContainsString(
            '<input><ingress><interface><name>ge-0/0/5.0</name></interface></ingress>'
            .'<egress><interface><name>ge-0/0/5.0</name></interface></egress></input>',
            $payload,
        );
        $this->assertStringContainsString(
            '<output><interface><name>ge-0/0/21.0</name></interface></output>',
            $payload,
        );
    }

    /**
     * Uzak aynalama: hedef bir VLAN olabilir.
     */
    public function test_cikis_vlani_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.mirroring.store', $switch), [
                'name' => 'uzak',
                'ingress_vlans' => ['ofis'],
                'output_vlan' => 'ayna',
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString('<input><ingress><vlan><name>ofis</name></vlan></ingress></input>', $payload);
        $this->assertStringContainsString('<output><vlan><name>ayna</name></vlan></output>', $payload);
    }

    /**
     * Çıkış hedefi TEKTİR: ya arayüz ya VLAN (Junos kuralı).
     */
    public function test_cikis_hedefi_ikisi_birden_verilemez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.mirroring.store', $switch), [
                'name' => 'izleme',
                'output_interface' => 'ge-0/0/21.0',
                'output_vlan' => 'ayna',
            ])
            ->assertSessionHasErrors('output_interface');

        $this->assertNull($device->connection, 'Reddedilen istekte cihaza bağlanılmamalı.');
    }

    /**
     * Cihaz, anahtarlama ailesinde olmayan porta aynalama çıkışı vermiyor:
     * "Analyzer output interface ge-0/0/21.0 should be configured with family
     * ethernet-switching" (test1'de ölçüldü). Panel önce söyler.
     */
    public function test_anahtarlama_disi_port_cikis_olamaz(): void
    {
        $switch = $this->switch();
        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/9',
            'port_mode' => PortMode::Routed,
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.mirroring.store', $switch), [
                'name' => 'izleme',
                'output_interface' => 'ge-0/0/9.0',
            ])
            ->assertSessionHasErrors('output_interface');

        $this->assertNull($device->connection);
    }

    /**
     * Kaynak port çıkış portu olamaz: port kendi kopyasını almaya çalışırdı.
     */
    public function test_kaynak_port_cikis_portu_olamaz(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.mirroring.store', $switch), [
                'name' => 'izleme',
                'ingress_interfaces' => ['ge-0/0/21.0'],
                'output_interface' => 'ge-0/0/21.0',
            ])
            ->assertSessionHasErrors('ingress_interfaces');

        $this->assertNull($device->connection);
    }

    /**
     * Panelin göstermediği satır düzenlemede KAYBOLMAZ ve kendi seviyesine
     * geri basılır.
     */
    public function test_korunan_ham_satir_geri_yazilir(): void
    {
        $switch = $this->switch();

        $analyzer = SwitchAnalyzer::factory()->for($switch, 'switch')->create([
            'name' => 'izleme',
            'ingress_interfaces' => ['ge-0/0/5.0'],
            'output_interface' => 'ge-0/0/21.0',
            'residual_xml' => [
                SwitchAnalyzer::RESIDUAL_ROOT => ['<uydurma-analyzer/>'],
                SwitchAnalyzer::RESIDUAL_INGRESS => ['<uydurma-ingress/>'],
                SwitchAnalyzer::RESIDUAL_OUTPUT => ['<uydurma-output/>'],
            ],
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.mirroring.update', [$switch, $analyzer]), [
                'ingress_interfaces' => ['ge-0/0/5.0'],
                'output_interface' => 'ge-0/0/21.0',
            ])
            ->assertSessionHasNoErrors();

        $payload = $device->payload();

        $this->assertStringContainsString(
            '<ingress><interface><name>ge-0/0/5.0</name></interface><uydurma-ingress/></ingress>',
            $payload,
            'Korunan satır kendi seviyesine geri yazılmadı.',
        );
        $this->assertStringContainsString(
            '<interface><name>ge-0/0/21.0</name></interface><uydurma-output/></output>',
            $payload,
        );
        $this->assertStringContainsString('<uydurma-analyzer/></analyzer>', $payload);
    }

    /**
     * Ad cihazda kimliktir: firewall terimleri de aynalamaya adıyla atıf
     * yapar. Sessizce yok saymak yerine açıkça reddedilir.
     */
    public function test_ad_degistirilemez(): void
    {
        $switch = $this->switch();

        $analyzer = SwitchAnalyzer::factory()->for($switch, 'switch')->create(['name' => 'izleme']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.mirroring.update', [$switch, $analyzer]), [
                'name' => 'baska-ad',
                'output_interface' => 'ge-0/0/21.0',
            ])
            ->assertSessionHasErrors('name');

        $this->assertNull($device->connection);
    }

    public function test_aynalama_cihazdan_silinir(): void
    {
        $switch = $this->switch();

        $analyzer = SwitchAnalyzer::factory()->for($switch, 'switch')->create(['name' => 'izleme']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->delete(route('switches.mirroring.destroy', [$switch, $analyzer]))
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<analyzer delete="delete"><name>izleme</name></analyzer>',
            $device->payload(),
        );
    }

    /**
     * Aynalama KENDİ yetkisidir: spanning tree ya da fırtına denetimi yetkisi
     * trafiği kopyalama yetkisini ima etmez.
     */
    public function test_yetkisiz_kullanici_yazamaz(): void
    {
        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        $this->actingAs($user->fresh())
            ->post(route('switches.mirroring.store', $switch), [
                'name' => 'izleme',
                'output_interface' => 'ge-0/0/21.0',
            ])
            ->assertForbidden();
    }

    /**
     * Ekran gerçekten çiziliyor mu: aynalama satırı, hedefi ve korunan satır
     * rozeti görünür. Sunucu testleri geçtiği hâlde ekran çizilmeyebilir;
     * bu test onu yakalar.
     */
    public function test_ekran_aynalamalari_listeler(): void
    {
        $switch = $this->switch();

        SwitchAnalyzer::factory()->for($switch, 'switch')->create([
            'name' => 'izleme',
            'ingress_interfaces' => ['ge-0/0/5.0'],
            'output_interface' => 'ge-0/0/21.0',
            'residual_xml' => [SwitchAnalyzer::RESIDUAL_ROOT => ['<uydurma-analyzer/>']],
        ]);

        $this->actingAs($this->operator())
            ->get(route('switches.mirroring', $switch))
            ->assertOk()
            ->assertSee('izleme')
            ->assertSee('ge-0/0/21.0')
            ->assertSee('1 korunan satır');
    }

    public function test_goruntuleme_yetkisi_olmayan_ekrani_acamaz(): void
    {
        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_mirroring', []);

        $this->actingAs($user->fresh())
            ->get(route('switches.mirroring', $switch))
            ->assertForbidden();
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

        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'if_index' => 505,
            'port_mode' => PortMode::Access,
        ]);

        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/21',
            'if_index' => 521,
            'port_mode' => PortMode::Trunk,
        ]);

        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ofis', 'vlan_tag' => 10]);
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ayna', 'vlan_tag' => 11]);

        return $switch->refresh();
    }

    /**
     * ae ARAYÜZÜ aynalamada kullanılamaz.
     *
     * Cihaz: "Aggregated interface cannot be configured as Analyzer monitor
     * interface" (test1, commit check). Panel bunu önceden söylüyor.
     */
    public function test_ae_arayuzu_aynalamada_kullanilamaz(): void
    {
        $switch = $this->switch();
        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ae0',
            'port_mode' => PortMode::Trunk,
        ]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.mirroring.store', $switch), [
                'name' => 'ayna',
                'ingress_interfaces' => ['ae0.0'],
                'output_interface' => 'ge-0/0/21.0',
            ])
            ->assertSessionHasErrors('output_interface');

        $this->assertNull($device->connection);
    }

    /**
     * Çıkış portu HİÇBİR VLAN'a üye olmamalı.
     *
     * Cihaz: "Analyzer output interface should not be added to any vlan"
     * (test1, commit check). Aynalama çıkışı anahtarlamaya katılırsa
     * kopyalanan trafik ağa geri karışır.
     */
    public function test_vlana_uye_port_cikis_olamaz(): void
    {
        $switch = $this->switch();
        $hedef = $switch->ports()->firstWhere('name', 'ge-0/0/21');
        $hedef->vlans()->attach($switch->vlans()->firstWhere('name', 'ofis'), ['is_untagged' => true]);

        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.mirroring.store', $switch), [
                'name' => 'ayna',
                'ingress_interfaces' => ['ge-0/0/5.0'],
                'output_interface' => $hedef->name.'.0',
            ])
            ->assertSessionHasErrors('output_interface');

        $this->assertNull($device->connection);
    }

    /**
     * Örnekleme oranının üst sınırı CİHAZDAN geliyor: "Value 2048 is not
     * within range (0..2047)" (test1, commit check).
     */
    public function test_oran_cihazin_sinirini_asamaz(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.mirroring.store', $switch), [
                'name' => 'ayna',
                'ingress_interfaces' => ['ge-0/0/5.0'],
                'output_interface' => 'ge-0/0/21.0',
                'ratio' => 2048,
            ])
            ->assertSessionHasErrors('ratio');

        $this->assertNull($device->connection);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }
}
