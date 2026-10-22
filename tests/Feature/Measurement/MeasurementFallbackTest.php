<?php

declare(strict_types=1);

namespace Tests\Feature\Measurement;

use App\Enums\CredentialProtocol;
use App\Enums\MeasurementSource;
use App\Enums\PortStatus;
use App\Enums\SwitchStatus;
use App\Jobs\SyncSwitchPortsJob;
use App\Jobs\SyncSwitchStatusJob;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\User;
use App\Services\Measurement\MeasurementCircuit;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * İzleme sistemi devre dışıyken ya da erişilemezken panelin körelmemesi.
 *
 * Buradaki kural tek cümledir: ölçüm alınamıyorsa "bilinmiyor" denir,
 * "çevrimdışı" denmez — ve alınabilecek başka bir kaynak varsa ona sorulur.
 */
final class MeasurementFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_izleme_sistemi_erisilemezken_olcum_cihazdan_okunur(): void
    {
        Http::fake(['*/api_jsonrpc.php' => Http::response(status: 500)]);
        config(['zabbix.url' => 'https://izleme.test', 'zabbix.api_token' => 'jeton']);

        $switch = $this->switch();
        $this->fakeDevice();

        (new SyncSwitchStatusJob($switch->id))->handle(app('App\Services\Measurement\MeasurementResolver'));

        $switch->refresh();

        $this->assertSame(SwitchStatus::Online, $switch->status);
        $this->assertSame(MeasurementSource::Netconf, $switch->measurement_source);

        // cpu-idle 94 → kullanım %6.
        $this->assertSame(6, $switch->cpu_usage);
        $this->assertSame(50, $switch->memory_usage);
        $this->assertSame(39, $switch->temperature);
        $this->assertSame(43772, $switch->uptime_seconds);
    }

    public function test_hicbir_kaynak_cevap_veremezse_durum_bilinmiyor_olur_ve_veri_silinmez(): void
    {
        config(['zabbix.enabled' => false, 'snmp.enabled' => false]);

        // NETCONF kimliği olmayan switch: sorulacak kimse yok.
        $switch = NetworkSwitch::factory()->create([
            'status' => SwitchStatus::Online,
            'cpu_usage' => 42,
            'temperature' => 31,
        ]);

        (new SyncSwitchStatusJob($switch->id))->handle(app('App\Services\Measurement\MeasurementResolver'));

        $switch->refresh();

        $this->assertSame(SwitchStatus::Unknown, $switch->status);

        // Son bilinen ölçümler yerinde kalır: bilinmezlik veriyi silmez.
        $this->assertSame(42, $switch->cpu_usage);
        $this->assertSame(31, $switch->temperature);
    }

    public function test_devre_kesici_acildiktan_sonra_kaynak_yeniden_denenmez(): void
    {
        Http::fake(['*/api_jsonrpc.php' => Http::response(status: 500)]);
        config([
            'zabbix.url' => 'https://izleme.test',
            'zabbix.api_token' => 'jeton',
            'measurement.failure_ttl' => 60,
        ]);

        $switch = $this->switch();
        $this->fakeDevice();
        $resolver = app('App\Services\Measurement\MeasurementResolver');

        (new SyncSwitchStatusJob($switch->id))->handle($resolver);
        $firstRound = count(Http::recorded());

        (new SyncSwitchStatusJob($switch->id))->handle($resolver);

        // İkinci turda izleme sistemine hiç gidilmemeli: devre kesik.
        $this->assertSame($firstRound, count(Http::recorded()));
    }

    public function test_port_olcumleri_cihazdan_okunur_ve_ornek_saklanir(): void
    {
        config(['measurement.source' => MeasurementSource::Netconf->value]);

        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/0',
            'if_index' => 525,
            'oper_status' => PortStatus::Down,
            'speed_mbps' => null,
        ]);

        $this->fakeDevice();

        (new SyncSwitchPortsJob($switch->id))->handle(
            app('App\Services\Measurement\MeasurementResolver'),
            app('App\Services\Zabbix\ZabbixHostMap'),
            app('App\Services\Zabbix\ZabbixItemReader'),
        );

        $port->refresh();

        $this->assertSame(PortStatus::Up, $port->oper_status);

        // Otomatik anlaşmada hız karşı taraf bilgisinden okunur.
        $this->assertSame(1000, $port->speed_mbps);

        // Geçmiş grafiği için örnek: cihaz geçmiş tutmaz, panel tutar.
        $this->assertDatabaseHas('switch_port_samples', [
            'switch_port_id' => $port->id,
            'in_bps' => 4000,
        ]);
    }

    public function test_izleme_sistemi_kullanilirken_ornek_saklanmaz(): void
    {
        config(['measurement.source' => MeasurementSource::Zabbix->value]);
        Http::fake(['*/api_jsonrpc.php' => Http::response($this->zabbixFixture('item-get-interfaces'))]);
        config(['zabbix.url' => 'https://izleme.test', 'zabbix.api_token' => 'jeton']);

        $switch = $this->switch(['zabbix_host_id' => '90501']);
        SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/0', 'if_index' => 518]);

        (new SyncSwitchPortsJob($switch->id))->handle(
            app('App\Services\Measurement\MeasurementResolver'),
            app('App\Services\Zabbix\ZabbixHostMap'),
            app('App\Services\Zabbix\ZabbixItemReader'),
        );

        // Geçmiş izleme sisteminde zaten var; ikinci bir kopya tutulmaz.
        $this->assertDatabaseCount('switch_port_samples', 0);
    }

    public function test_port_sayfasi_izleme_sistemi_coktugunde_de_acilir(): void
    {
        Http::fake(['*/api_jsonrpc.php' => Http::response(status: 500)]);
        config([
            'zabbix.url' => 'https://izleme.test',
            'zabbix.api_token' => 'jeton',
            'measurement.fallback' => false,
        ]);

        $switch = $this->switch();
        SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/7', 'if_index' => 532]);

        /*
         * Burada ZabbixApiException yükselirse render() her zaman JSON
         * döndürdüğü için HTML sayfa isteği 503 JSON olarak döner: izleme
         * sistemi çökünce port ekranı tamamen kullanılamaz olur.
         */
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('switches.ports', $switch))
            ->assertOk()
            ->assertSee('ge-0/0/7');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function switch(array $attributes = []): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'hostname' => 'jn32-test',
            'management_ip' => '198.18.20.195',
            ...$attributes,
        ]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    private function fakeDevice(): FakeJunosDevice
    {
        $device = new FakeJunosDevice(
            interfaces: [
                ['name' => 'ge-0/0/0', 'admin' => 'up', 'oper' => 'up', 'index' => 525, 'in_bps' => 4000, 'out_bps' => 8000],
            ],
        );

        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    /**
     * SÜREN ARIZA GÜNLÜĞÜ DOLDURMAZ.
     *
     * Devre kesici süresi kısa (60 sn), ölçüm turu 30 saniyede bir koşuyor:
     * ulaşılamayan tek bir cihaz saatte yüzlerce satır üretebilir. Arıza
     * sürerken saatte bir satır yeterli. Sınır yalnızca zamana bağlıdır: aynı kaynağın başka bir
     * okuması başarılı olduğu için sıfırlanmaz.
     */
    public function test_suren_ariza_saatte_bir_kez_gunluge_yazilir(): void
    {
        $switch = $this->switch();
        $cutter = app(MeasurementCircuit::class);

        $this->assertTrue(
            $cutter->shouldReport(MeasurementSource::Zabbix, $switch),
            'İlk arıza yazılmalı.',
        );

        $this->assertFalse(
            $cutter->shouldReport(MeasurementSource::Zabbix, $switch),
            'Aynı arıza tekrar yazılmamalı.',
        );

        /*
         * Aynı kaynağın BAŞKA bir okuması başarılı olabilir: bir turda hem
         * device hem ports okunur ve izleme sisteminde birinin verisi varken
         * öbürününki olmayabilir. Başarı sınırı sıfırlasaydı uyarı her turda
         * yeniden yazılırdı.
         */
        $cutter->recordSuccess(MeasurementSource::Zabbix, $switch);

        $this->assertFalse(
            $cutter->shouldReport(MeasurementSource::Zabbix, $switch),
            'Başarılı bir okuma saatlik sınırı sıfırlamamalı.',
        );

        $this->travel(61)->minutes();

        $this->assertTrue(
            $cutter->shouldReport(MeasurementSource::Zabbix, $switch),
            'Saat dolunca arızanın sürdüğü yeniden yazılmalı.',
        );
    }
}
