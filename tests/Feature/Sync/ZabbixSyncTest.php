<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Enums\CredentialProtocol;
use App\Enums\Duplex;
use App\Enums\PortStatus;
use App\Enums\PortType;
use App\Enums\SwitchStatus;
use App\Exceptions\ZabbixApiException;
use App\Jobs\SyncSwitchPortsJob;
use App\Jobs\SyncSwitchStatusJob;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Services\Measurement\MeasurementResolver;
use App\Services\Zabbix\ZabbixClient;
use App\Services\Zabbix\ZabbixHostMap;
use App\Services\Zabbix\ZabbixItemReader;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Bu testler Zabbix'e ve switchlere hiçbir gerçek istek atmaz:
 * TestCase içindeki preventStrayRequests bunu garanti eder.
 */
final class ZabbixSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'zabbix.url' => 'https://zabbix.test',
            'zabbix.api_token' => 'test-token',
        ]);

        Cache::flush();
    }

    /**
     * Zabbix'in JSON-RPC ucunu taklit eder.
     *
     * item.get yalnızca fixture'da tanımlı host kimlikleri için veri döndürür;
     * gerçek Zabbix de bilinmeyen bir kimlik için boş liste verir. Bu ayrım
     * bayat kimlik senaryosunun test edilebilmesi için şart.
     */
    private function fakeZabbix(string $itemFixture = 'item-get-status'): void
    {
        $knownHosts = ['90501', '90502'];

        Http::fake([
            '*/api_jsonrpc.php' => function ($request) use ($itemFixture, $knownHosts) {
                $data = $request->data();

                if (($data['method'] ?? '') === 'host.get') {
                    return Http::response($this->zabbixFixture('host-get'));
                }

                // item.get artık hostids'i dizi olarak gönderiyor (toplu okuma).
                $hostIds = array_values(array_intersect(
                    array_map(strval(...), (array) ($data['params']['hostids'] ?? [])),
                    $knownHosts,
                ));

                return Http::response($this->zabbixItems($hostIds, $itemFixture));
            },
        ]);
    }

    public function test_the_host_map_pairs_management_ips_with_host_ids(): void
    {
        $this->fakeZabbix();

        $this->assertSame(
            ['198.18.30.39' => '90501', '198.18.30.130' => '90502', '198.18.8.194' => '90502'],
            app(ZabbixHostMap::class)->map(),
        );
    }

    public function test_the_host_id_is_stored_on_the_switch_so_it_is_resolved_once(): void
    {
        $this->fakeZabbix();
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39', 'zabbix_host_id' => null]);

        $this->assertSame('90501', app(ZabbixHostMap::class)->hostIdFor($switch));
        $this->assertSame('90501', $switch->fresh()->zabbix_host_id);

        // İkinci çağrı kayıtlı değeri kullanır, API'ye gitmez.
        Http::fake();
        $this->assertSame('90501', app(ZabbixHostMap::class)->hostIdFor($switch->fresh()));
    }

    public function test_status_sync_writes_the_metrics(): void
    {
        $this->fakeZabbix();
        $switch = NetworkSwitch::factory()->create([
            'management_ip' => '198.18.30.39',
            'zabbix_host_id' => null,
            'cpu_usage' => null,
        ]);

        (new SyncSwitchStatusJob($switch->id))->handle(app(MeasurementResolver::class));

        $switch->refresh();

        // İki CPU item'ından yüksek olanı alınır (çok işlemcili şasi).
        $this->assertSame(88, $switch->cpu_usage);
        $this->assertSame(61, $switch->memory_usage);
        $this->assertSame(41, $switch->temperature);
        $this->assertSame(8352000, $switch->uptime_seconds);
        $this->assertNotNull($switch->last_sync_at);
    }

    public function test_a_breached_threshold_marks_the_switch_as_warning(): void
    {
        $this->fakeZabbix();
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39', 'zabbix_host_id' => null]);

        (new SyncSwitchStatusJob($switch->id))->handle(app(MeasurementResolver::class));

        // Fixture'da CPU %88, tehlike eşiği %85.
        $this->assertSame(SwitchStatus::Warning, $switch->fresh()->status);
    }

    public function test_izleme_sisteminde_olmayan_switch_cevrimdisi_degil_bilinmiyor_yazilir(): void
    {
        $this->fakeZabbix();
        $switch = NetworkSwitch::factory()->create(['management_ip' => '192.0.2.77', 'zabbix_host_id' => null]);

        (new SyncSwitchStatusJob($switch->id))->handle(app(MeasurementResolver::class));

        /*
         * Cihaz izleme sisteminde tanımlı değil ve NETCONF kimliği de yok:
         * kimse bir şey söyleyemiyor. "Çevrimdışı" demek uydurma olurdu —
         * cihaz pekâlâ ayakta olabilir, biz göremiyoruz.
         */
        $this->assertSame(SwitchStatus::Unknown, $switch->fresh()->status);
    }

    public function test_a_deleted_switch_does_not_break_the_job(): void
    {
        $this->fakeZabbix();

        (new SyncSwitchStatusJob(999_999))->handle(app(MeasurementResolver::class));

        $this->assertTrue(true);
    }

    public function test_interface_sync_stores_only_physical_ports(): void
    {
        $this->fakeZabbix('item-get-interfaces');
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39', 'zabbix_host_id' => null]);

        (new SyncSwitchPortsJob($switch->id))->handle(app(MeasurementResolver::class), app(ZabbixHostMap::class), app(ZabbixItemReader::class));

        $names = $switch->ports()->orderBy('name')->pluck('name')->all();

        // vlan.122 (ifType 135) ve lo0 (ifType 24) elenir, alt arayüz ge-0/0/0.0 da.
        $this->assertSame(['ae0', 'ge-0/0/0', 'ge-0/0/1', 'ge-0/1/2'], $names);
    }

    public function test_interface_attributes_are_translated(): void
    {
        $this->fakeZabbix('item-get-interfaces');
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39', 'zabbix_host_id' => null]);

        (new SyncSwitchPortsJob($switch->id))->handle(app(MeasurementResolver::class), app(ZabbixHostMap::class), app(ZabbixItemReader::class));

        $up = $switch->ports()->firstWhere('name', 'ge-0/0/0');
        $down = $switch->ports()->firstWhere('name', 'ge-0/0/1');

        $this->assertSame(PortStatus::Up, $up->oper_status);
        $this->assertSame(Duplex::Full, $up->duplex);
        // Zabbix bps verir (1000000000), veritabanında megabit tutulur.
        $this->assertSame(1000, $up->speed_mbps);
        $this->assertSame(518, $up->if_index);
        $this->assertSame(PortStatus::Down, $down->oper_status);
    }

    public function test_interfaces_that_disappear_are_removed(): void
    {
        $this->fakeZabbix('item-get-interfaces');
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39', 'zabbix_host_id' => null]);

        SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/47', 'if_index' => 999]);

        (new SyncSwitchPortsJob($switch->id))->handle(app(MeasurementResolver::class), app(ZabbixHostMap::class), app(ZabbixItemReader::class));

        $this->assertDatabaseMissing('switch_ports', ['name' => 'ge-0/0/47']);
    }

    /**
     * NETCONF kimliği olan cihazda arayüz listesinin sahibi NETCONF'tur.
     *
     * Zabbix'in keşif kuralı yeni bir arayüzü (özellikle yeni kurulan bir
     * ae'yi) görene kadar bir saat geçebilir; o arada burada silinirse ae ile
     * birlikte ÜYELİKLERİ de kaybolur ve iki iş birbirinin kaydını silip
     * yazmaya başlar.
     */
    /**
     * NETCONF'lu switchte Zabbix SATIR YARATMAZ: cihazda olmayan bir arayüz
     * envantere Zabbix üzerinden girmemeli, liste donanımdan okunur.
     */
    public function test_netconf_switchinde_zabbix_port_yaratmaz(): void
    {
        $this->fakeZabbix('item-get-interfaces');
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39', 'zabbix_host_id' => null]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        (new SyncSwitchPortsJob($switch->refresh()->id))->handle(app(MeasurementResolver::class), app(ZabbixHostMap::class), app(ZabbixItemReader::class));

        $this->assertSame(0, SwitchPort::query()->where('switch_id', $switch->id)->count());
    }

    /**
     * Mevcut satırda YALNIZCA ölçümler tazelenir; yapılandırma alanlarına
     * dokunulmaz — onların sahibi NETCONF'tur.
     */
    public function test_netconf_switchinde_yalnizca_olcumler_tazelenir(): void
    {
        $this->fakeZabbix('item-get-interfaces');
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39', 'zabbix_host_id' => null]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        $port = SwitchPort::factory()->for($switch->refresh(), 'switch')->create([
            'name' => 'ge-0/0/1',
            'description' => 'NETCONF yazdi',
            'speed_mbps' => null,
        ]);

        (new SyncSwitchPortsJob($switch->id))->handle(app(MeasurementResolver::class), app(ZabbixHostMap::class), app(ZabbixItemReader::class));

        $port->refresh();

        // Açıklama NETCONF'undur, Zabbix ezmez.
        $this->assertSame('NETCONF yazdi', $port->description);
        $this->assertNotNull($port->last_polled_at);
    }

    public function test_netconf_switchinde_zabbix_port_silmez(): void
    {
        $this->fakeZabbix('item-get-interfaces');
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39', 'zabbix_host_id' => null]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        SwitchPort::factory()->for($switch->refresh(), 'switch')
            ->create(['name' => 'ae0', 'if_index' => 999, 'interface_kind' => PortType::Aggregated]);

        (new SyncSwitchPortsJob($switch->id))->handle(app(MeasurementResolver::class), app(ZabbixHostMap::class), app(ZabbixItemReader::class));

        $this->assertDatabaseHas('switch_ports', ['name' => 'ae0']);
    }

    /**
     * Zabbix bir ae'yi bizden önce keşfederse "physical" varsayılanıyla
     * yazılır ve toplama ekranında hiç görünmezdi; tür ADDAN çıkarılır.
     */
    public function test_ae_arayuzu_zabbixten_gelse_de_dogru_turle_yazilir(): void
    {
        $this->fakeZabbix('item-get-interfaces');
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39', 'zabbix_host_id' => null]);

        (new SyncSwitchPortsJob($switch->id))->handle(app(MeasurementResolver::class), app(ZabbixHostMap::class), app(ZabbixItemReader::class));

        $aggregates = SwitchPort::query()
            ->where('switch_id', $switch->id)
            ->where('name', 'like', 'ae%')
            ->get();

        foreach ($aggregates as $port) {
            $this->assertSame(PortType::Aggregated, $port->interface_kind);
        }

        // Fiziksel portlar etkilenmez.
        $this->assertSame(
            PortType::Physical,
            SwitchPort::query()->where('switch_id', $switch->id)->where('name', 'like', 'ge-%')->first()?->interface_kind,
        );
    }

    public function test_an_api_error_is_raised_as_an_exception(): void
    {
        Http::fake(['*/api_jsonrpc.php' => Http::response($this->zabbixFixture('error'))]);

        $this->expectException(ZabbixApiException::class);
        $this->expectExceptionMessage('Not authorised');

        app(ZabbixClient::class)->call('host.get');
    }

    public function test_basarisiz_is_switchi_cevrimdisi_degil_bilinmiyor_yapar(): void
    {
        $switch = NetworkSwitch::factory()->create();

        (new SyncSwitchStatusJob($switch->id))->failed();

        // Okuyamamak, cihazın kapalı olduğu bilgisi değildir.
        $this->assertSame(SwitchStatus::Unknown, $switch->fresh()->status);
    }

    public function test_the_command_queues_one_job_per_switch(): void
    {
        Queue::fake();
        NetworkSwitch::factory()->count(3)->create();

        $this->artisan('network:sync-status')->assertSuccessful();

        Queue::assertPushed(SyncSwitchStatusJob::class, 3);
    }

    public function test_the_command_can_target_a_single_switch(): void
    {
        Queue::fake();
        NetworkSwitch::factory()->create(['hostname' => 'HEDEF']);
        NetworkSwitch::factory()->count(2)->create();

        $this->artisan('network:sync-status', ['switch' => 'HEDEF'])->assertSuccessful();

        Queue::assertPushed(SyncSwitchStatusJob::class, 1);
    }

    public function test_jobs_go_to_the_dedicated_queue(): void
    {
        Queue::fake();
        NetworkSwitch::factory()->create();

        $this->artisan('network:sync-ports');

        Queue::assertPushed(
            SyncSwitchPortsJob::class,
            fn (SyncSwitchPortsJob $job): bool => $job->queue === config('zabbix.queue'),
        );
    }

    public function test_both_sync_commands_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => $event->command ?? '')
            ->implode(' ');

        $this->assertStringContainsString('network:sync-status', $commands);
        $this->assertStringContainsString('network:sync-ports', $commands);
    }

    public function test_a_stale_host_id_is_resolved_again_instead_of_reporting_a_healthy_switch(): void
    {
        $this->fakeZabbix();

        // Zabbix'te host yeniden oluşturulmuş: kayıtlı kimlik artık karşılıksız.
        $switch = NetworkSwitch::factory()->zabbixHost('99999')->create([
            'management_ip' => '198.18.30.39',
            'status' => SwitchStatus::Offline,
        ]);

        (new SyncSwitchStatusJob($switch->id))->handle(app(MeasurementResolver::class));

        $switch->refresh();

        $this->assertSame('90501', $switch->zabbix_host_id);
        $this->assertSame(88, $switch->cpu_usage);
        $this->assertSame(SwitchStatus::Warning, $switch->status);
    }

    public function test_a_host_with_no_items_is_not_reported_healthy(): void
    {
        Http::fake([
            '*/api_jsonrpc.php' => function ($request) {
                $method = $request->data()['method'] ?? '';

                return Http::response($method === 'host.get'
                    ? $this->zabbixFixture('host-get')
                    : ['jsonrpc' => '2.0', 'result' => [], 'id' => 1]);
            },
        ]);

        $switch = NetworkSwitch::factory()->create([
            'management_ip' => '198.18.30.39',
            'status' => SwitchStatus::Online,
            'cpu_usage' => 40,
        ]);

        (new SyncSwitchStatusJob($switch->id))->handle(app(MeasurementResolver::class));

        /*
         * Metrik yoksa switch "çevrimiçi ama boş" görünmemeli. Ama
         * "çevrimdışı" da denmez: izleme sistemi bu cihaz hakkında bir şey
         * bilmiyor, cihazın kapalı olduğunu söylemiyor.
         */
        $this->assertSame(SwitchStatus::Unknown, $switch->fresh()->status);

        // Son bilinen ölçüm korunur; bilinmezlik veriyi silmez.
        $this->assertSame(40, $switch->fresh()->cpu_usage);
    }

    public function test_the_factory_does_not_invent_a_zabbix_host_id(): void
    {
        $this->assertNull(NetworkSwitch::factory()->create()->zabbix_host_id);
    }

    public function test_aggregate_uplink_interfaces_are_tracked(): void
    {
        $this->fakeZabbix('item-get-interfaces');
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39']);

        (new SyncSwitchPortsJob($switch->id))->handle(
            app(MeasurementResolver::class),
            app(ZabbixHostMap::class),
            app(ZabbixItemReader::class),
        );

        // ae0 fiziksel port değildir ama uplink olduğu için izlenir.
        $this->assertSame('UP-Link', $switch->ports()->firstWhere('name', 'ae0')->description);
    }

    public function test_port_descriptions_come_from_the_item_name(): void
    {
        $this->fakeZabbix('item-get-interfaces');
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39']);

        (new SyncSwitchPortsJob($switch->id))->handle(
            app(MeasurementResolver::class),
            app(ZabbixHostMap::class),
            app(ZabbixItemReader::class),
        );

        // Zabbix ifAlias'ı item adının parantezinde taşır.
        $this->assertSame('UP-Link-2/1', $switch->ports()->firstWhere('name', 'ge-0/1/2')->description);

        // Parantezi boş olan portta açıklama yoktur.
        $this->assertNull($switch->ports()->firstWhere('name', 'ge-0/0/0')->description);
    }

    public function test_a_zero_speed_is_stored_as_unknown_not_zero(): void
    {
        $this->fakeZabbix('item-get-interfaces');
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39']);

        (new SyncSwitchPortsJob($switch->id))->handle(
            app(MeasurementResolver::class),
            app(ZabbixHostMap::class),
            app(ZabbixItemReader::class),
        );

        // Kapalı portun hızı 0 gelir; "0 Mb" yerine bilinmiyor olmalı.
        $this->assertNull($switch->ports()->firstWhere('name', 'ge-0/0/1')->speed_mbps);
    }

    public function test_hardware_inventory_is_taken_from_zabbix(): void
    {
        $this->fakeZabbix();
        $switch = NetworkSwitch::factory()->create([
            'management_ip' => '198.18.30.39',
            'model' => 'elle yazilmis yanlis model',
            'serial_number' => null,
        ]);

        (new SyncSwitchStatusJob($switch->id))->handle(app(MeasurementResolver::class));

        $switch->refresh();

        $this->assertSame('Juniper EX2200-48T-4G Ethernet Switch', $switch->model);
        $this->assertSame('TESTSN0000001', $switch->serial_number);
    }

    public function test_an_empty_zabbix_value_does_not_wipe_stored_data(): void
    {
        $this->fakeZabbix();

        // Fixture'da system.sw.os boş; kayıtlı sürüm korunmalı.
        $switch = NetworkSwitch::factory()->create([
            'management_ip' => '198.18.30.39',
            'junos_version' => '21.4R3-S5',
        ]);

        (new SyncSwitchStatusJob($switch->id))->handle(app(MeasurementResolver::class));

        $this->assertSame('21.4R3-S5', $switch->fresh()->junos_version);
    }
}
