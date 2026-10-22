<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Enums\PortType;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\Vlan;
use App\Services\Netconf\Transport\NetconfTransport;
use App\Support\Netconf\DeviceWriteScenarios;
use App\Support\Netconf\ScenarioNotApplicable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Doğrulama raporu, PANEL hatasını SENARYO artefaktından ayırmalı.
 *
 * İki farklı şey aynı kırmızı satırla gösterilmez:
 *
 *   - panelin ürettiği Junos'u cihazın reddetmesi (gerçek bulgu),
 *   - senaryonun cihazda olmayan bir duruma yazması (artefakt).
 *
 * Örnek: ae0 tanımlı olmayan bir switchte "toplama · ae ayarları" FİZİKSEL
 * porta düşer ve cihaz "syntax error" verir; panelde hata yoktur. Aynısı
 * ELS'te "all" kısayolunu kullanan senaryolarda olur.
 *
 * Bu testler ayrımın kapanmamasını kilitler.
 */
final class DeviceVerificationReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ae0 yoksa senaryo fiziksel porta DÜŞMEZ; gerekçesiyle atlanır.
     */
    public function test_ae_arayuzu_yoksa_senaryo_cihaza_gitmez(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5');

        $scenarios = app(DeviceWriteScenarios::class)->all($switch);

        $this->expectException(ScenarioNotApplicable::class);

        $scenarios['toplama · ae ayarları']();
    }

    /**
     * ae0 varsa senaryo normal çalışır: atlama yalnızca ön koşul yokken.
     */
    public function test_ae_arayuzu_varsa_senaryo_calisir(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ae0', PortType::Aggregated);

        $device = $this->device();

        app(DeviceWriteScenarios::class)->all($switch)['toplama · ae ayarları']();

        $this->assertStringContainsString('ae0', $device->payload());
    }

    /**
     * ELS "all" kısayolunu kabul etmiyor; senaryo GERÇEK VLAN ve porta
     * yazar, yoksa "all" göndererek olmayan bir panel hatası üretirdi.
     */
    public function test_els_senaryolari_all_kisayolunu_kullanmaz(): void
    {
        $switch = $this->elsSwitch();
        $this->port($switch, 'ge-0/0/5');
        Vlan::factory()->for($switch, 'switch')->create(['name' => 'ofis', 'vlan_tag' => 30]);

        $device = $this->device();

        app(DeviceWriteScenarios::class)->all($switch)['snooping · vlan examine-dhcp']();

        $payload = $device->payload();

        $this->assertStringContainsString('<name>ofis</name>', $payload);
        $this->assertStringNotContainsString('<name>all</name>', $payload);
    }

    /**
     * ELS öncesinde kısayol GEÇERLİ; senaryo onu kullanmaya devam eder.
     */
    public function test_els_oncesi_senaryolari_all_kisayolunu_kullanir(): void
    {
        $switch = $this->switch();

        $device = $this->device();

        app(DeviceWriteScenarios::class)->all($switch)['snooping · vlan examine-dhcp']();

        $this->assertStringContainsString('<name>all</name>', $device->payload());
    }

    /**
     * ELS'te ne VLAN ne uygun port varsa senaryo cihaza gitmez.
     */
    public function test_els_te_dayanak_yoksa_senaryo_atlanir(): void
    {
        $switch = $this->elsSwitch();

        $scenarios = app(DeviceWriteScenarios::class)->all($switch);

        $this->expectException(ScenarioNotApplicable::class);

        $scenarios['snooping · vlan examine-dhcp']();
    }

    /**
     * Rapor üç kovayı ayrı sayar ve kırmızı satır varsa komut BAŞARISIZ
     * döner: yeşil çıktının içinde kaybolan bulgu olmasın.
     */
    public function test_rapor_kirmizi_satirda_basarisiz_doner(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5');

        $this->app->instance(
            NetconfTransport::class,
            new FakeJunosDevice(loadError: 'syntax error'),
        );

        $this->artisan('network:verify-writes', [
            'switch' => $switch->hostname,
            '--only' => 'firtina · yüzde',
        ])
            ->expectsOutputToContain('GECTI 0 · RED 1 · ATLANDI 0')
            ->assertFailed();
    }

    public function test_rapor_temiz_gecerse_basarili_doner(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/5');

        $this->device();

        $this->artisan('network:verify-writes', [
            'switch' => $switch->hostname,
            '--only' => 'firtina · yüzde',
        ])
            ->expectsOutputToContain('GECTI 1 · RED 0 · ATLANDI 0')
            ->assertSuccessful();
    }

    /**
     * Atlanan senaryo RED sayılmaz ve komutu düşürmez.
     */
    public function test_atlanan_senaryo_kirmizi_sayilmaz(): void
    {
        $switch = $this->elsSwitch();

        $this->device();

        $this->artisan('network:verify-writes', [
            'switch' => $switch->hostname,
            '--only' => 'snooping · vlan examine-dhcp',
        ])
            ->expectsOutputToContain('ATLANDI (ön koşul)')
            ->expectsOutputToContain('GECTI 0 · RED 0 · ATLANDI 1')
            ->assertSuccessful();
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'management_ip' => '198.18.20.195',
            'schema_profile' => JunosSchemaProfile::PreEls,
        ]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    private function elsSwitch(): NetworkSwitch
    {
        $switch = $this->switch();
        $switch->forceFill(['schema_profile' => JunosSchemaProfile::Els])->save();

        return $switch->refresh();
    }

    private function port(
        NetworkSwitch $switch,
        string $name,
        PortType $kind = PortType::Physical,
    ): SwitchPort {
        return SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => $name,
            'port_mode' => PortMode::Access,
            'interface_kind' => $kind,
        ]);
    }
}
