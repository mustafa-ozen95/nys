<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\PortMode;
use App\Enums\PortStatus;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SwitchPortsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['zabbix.url' => 'https://zabbix.test', 'zabbix.api_token' => 'test-token']);
        Cache::flush();
    }

    /**
     * LİNKİ OLMAYAN PORTA HIZ BASILMAZ.
     *
     * Değer SNMP'den (ifSpeed) geliyor ve Junos, linki olmayan bakır portta
     * bunu 100 Mbps diye bildiriyor. Otomatik anlaşmaya ayarlanmış, kablosu
     * takılı olmayan bir port panelde "100 Mbps" görünüyor ve zorla 100'e
     * sabitlenmiş sanılıyordu — ölçüm değeri, niyet değil.
     */
    public function test_linki_olmayan_porta_hiz_basilmaz(): void
    {
        $switch = $this->switch();

        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'port_mode' => PortMode::Access,
            'oper_status' => PortStatus::Down,
            'speed_mbps' => 100,
        ]);

        SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/6',
            'port_mode' => PortMode::Access,
            'oper_status' => PortStatus::Up,
            'speed_mbps' => 1000,
        ]);

        $this->fakeZabbix();

        $portlar = $this->actingAs($this->operator())
            ->get(route('switches.ports', $switch))
            ->assertOk()
            ->viewData('ports');

        $this->assertSame('—', $portlar->firstWhere('model.name', 'ge-0/0/5')['link_speed_display']);
        $this->assertSame('1000 Mbps', $portlar->firstWhere('model.name', 'ge-0/0/6')['link_speed_display']);
    }

    private function fakeZabbix(string $itemFixture = 'item-get-interfaces'): void
    {
        Http::fake([
            '*/api_jsonrpc.php' => function ($request) use ($itemFixture) {
                $data = $request->data();

                return Http::response(match ($data['method'] ?? '') {
                    'host.get' => $this->zabbixFixture('host-get'),
                    'history.get', 'trend.get' => $this->zabbixFixture('history-get'),
                    default => array_intersect(array_map(strval(...), (array) ($data['params']['hostids'] ?? [])), ['90501', '90502']) !== []
                        ? $this->zabbixFixture($itemFixture)
                        : ['jsonrpc' => '2.0', 'result' => [], 'id' => 1],
                });
            },
        ]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        return $user->fresh();
    }

    private function switch(): NetworkSwitch
    {
        return NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39', 'hostname' => 'sw-test-1']);
    }

    public function test_the_port_screen_requires_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('switches.ports', $this->switch()))
            ->assertForbidden();
    }

    public function test_it_lists_the_ports_of_the_switch(): void
    {
        $this->fakeZabbix();
        $switch = $this->switch();

        SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/0', 'if_index' => 518]);
        SwitchPort::factory()->for($switch, 'switch')->down()->create(['name' => 'ge-0/0/1', 'if_index' => 519]);

        $this->actingAs($this->operator())
            ->get(route('switches.ports', $switch))
            ->assertOk()
            ->assertSee('ge-0/0/0')
            ->assertSee('ge-0/0/1')
            ->assertSee('1 / 2 açık');
    }

    public function test_ports_can_be_filtered(): void
    {
        $this->fakeZabbix();
        $switch = $this->switch();

        SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/0', 'description' => 'kamera']);
        SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/1', 'description' => 'yazici']);

        $this->actingAs($this->operator())
            ->get(route('switches.ports', ['switch' => $switch, 'q' => 'kamera']))
            ->assertOk()
            ->assertSee('ge-0/0/0')
            ->assertDontSee('ge-0/0/1');
    }

    public function test_ports_of_another_switch_are_not_shown(): void
    {
        $this->fakeZabbix();
        $switch = $this->switch();

        SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'BU-SWITCHIN-PORTU']);
        SwitchPort::factory()->create(['name' => 'BASKA-SWITCHIN-PORTU']);

        $this->actingAs($this->operator())
            ->get(route('switches.ports', $switch))
            ->assertOk()
            ->assertSee('BU-SWITCHIN-PORTU')
            ->assertDontSee('BASKA-SWITCHIN-PORTU');
    }

    public function test_the_screen_does_not_query_per_port(): void
    {
        $this->fakeZabbix();
        $switch = $this->switch();
        SwitchPort::factory()->count(24)->for($switch, 'switch')->create();

        $user = $this->operator();

        DB::enableQueryLog();
        $this->actingAs($user)->get(route('switches.ports', $switch))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        /*
         * Nesne gezinmesi artık her sekmenin görüntüleme yetkisini soruyor;
         * yetki matrisi istek başına BİR kez okunur ve önbelleklenir. Sabit
         * maliyettir, port sayısıyla büyümez — testin asıl işi olan "port
         * başına ek sorgu" denetimi bozulmaz.
         *
         * Rol de sabit bir sorgudur: isAdmin() her yetki kontrolünün ilk
         * sorusu ve User modelinde eager yükleniyor.
         *
         * Firewall filtresi bağlamaları da TEK sorgudur: bağlama filtrenin
         * üstünde durduğu için bütün filtreler bir kez okunup ters çevriliyor.
         * Port başına sorulsaydı N+1 olurdu; testin koruduğu tam olarak budur.
         *
         * Adresler de öyle: port ekranından IP verilebildiği için adresler
         * eager yükleniyor ve adres önerileri bir kez okunuyor. İkisi de
         * sabit maliyettir, port sayısıyla büyümez.
         *
         * Süren yazma işleri de TEK sorgudur: hangi portun kilitli olduğu
         * switch başına bir kez okunup eşlenir. Port başına sorulsaydı
         * N+1 olurdu; testin koruduğu tam olarak budur.
         *
         * Merkez kimliği de SABİT bir sorgudur ve yalnızca doğrulama aralığı
         * dolduğunda çalışır: kullanıcının merkezdeki durumu, oturum açıkken
         * de sorulmalı (bkz. VerifyIdentityStatus). Testte önbellek her
         * istekte boş olduğu için hep görünür; üretimde kullanıcı başına
         * beş dakikada bir.
         */
        $this->assertLessThanOrEqual(17, $count, "Beklenenden fazla sorgu: {$count}");
    }

    public function test_the_traffic_endpoint_returns_a_series(): void
    {
        $this->fakeZabbix();
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['if_index' => 518]);

        $this->actingAs($this->operator())
            ->getJson(route('ajax.port.traffic', [$switch, $port]).'?range=1h')
            ->assertOk()
            ->assertJsonStructure([
                'labels',
                'series' => [['label', 'tone', 'values']],
                'scale' => ['divisor', 'unit'],
                'aggregated',
            ]);
    }

    public function test_an_unknown_range_is_rejected(): void
    {
        $this->fakeZabbix();
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['if_index' => 518]);

        $this->actingAs($this->operator())
            ->getJson(route('ajax.port.traffic', [$switch, $port]).'?range=100y')
            ->assertStatus(422);
    }

    public function test_the_traffic_endpoint_refuses_a_port_of_another_switch(): void
    {
        $this->fakeZabbix();
        $switch = $this->switch();
        $foreign = SwitchPort::factory()->create();

        $this->actingAs($this->operator())
            ->getJson(route('ajax.port.traffic', [$switch, $foreign]))
            ->assertNotFound();
    }

    public function test_the_traffic_endpoint_requires_permission(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create();

        $this->actingAs(User::factory()->create())
            ->getJson(route('ajax.port.traffic', [$switch, $port]))
            ->assertForbidden();
    }

    public function test_a_port_without_zabbix_data_yields_an_empty_series(): void
    {
        $this->fakeZabbix();
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create(['if_index' => 99999]);

        $this->actingAs($this->operator())
            ->getJson(route('ajax.port.traffic', [$switch, $port]))
            ->assertOk()
            ->assertJson(['labels' => []]);
    }
}
