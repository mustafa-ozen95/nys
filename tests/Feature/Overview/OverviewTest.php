<?php

declare(strict_types=1);

namespace Tests\Feature\Overview;

use App\Enums\SwitchStatus;
use App\Models\ActivityLog;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Switching\NetworkOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['zabbix.url' => 'https://zabbix.test', 'zabbix.api_token' => 'test-token']);
        Cache::flush();
    }

    private function fakeZabbix(): void
    {
        Http::fake([
            '*/api_jsonrpc.php' => function ($request) {
                $data = $request->data();

                if (($data['method'] ?? '') === 'host.get') {
                    return Http::response($this->zabbixFixture('host-get'));
                }

                $hostIds = array_values(array_intersect(
                    array_map(strval(...), (array) ($data['params']['hostids'] ?? [])),
                    ['90501', '90502'],
                ));

                return Http::response($this->zabbixItems($hostIds, 'item-get-interfaces'));
            },
        ]);
    }

    private function reader(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        return $user->fresh();
    }

    public function test_it_shows_the_busiest_ports_with_live_traffic(): void
    {
        $this->fakeZabbix();
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.30.39', 'hostname' => 'sw-test-1']);
        SwitchPort::factory()->for($switch, 'switch')->create(['name' => 'ge-0/0/0', 'if_index' => 518]);

        $this->actingAs($this->reader())
            ->get(route('overview'))
            ->assertOk()
            ->assertSee('En yoğun portlar')
            ->assertSee('ge-0/0/0');
    }

    public function test_genel_bakis_butun_switchleri_tek_cagrida_okur(): void
    {
        $this->fakeZabbix();

        foreach (['198.18.30.39', '198.18.30.130'] as $ip) {
            $switch = NetworkSwitch::factory()->create(['management_ip' => $ip]);
            SwitchPort::factory()->for($switch, 'switch')->create();
        }

        $this->actingAs($this->reader())->get(route('overview'))->assertOk();

        $itemCagrilari = collect(Http::recorded())
            ->filter(fn (array $kayit): bool => ($kayit[0]->data()['method'] ?? '') === 'item.get')
            ->count();

        /*
         * Tek toplu item.get atılır: switch başına ayrı çağrı, genel bakışta
         * switch sayısı kadar gidiş dönüş demek olurdu.
         */
        $this->assertSame(1, $itemCagrilari);
    }

    public function test_the_page_survives_an_unreachable_monitoring_system(): void
    {
        // Zabbix hata döndürüyor: envanter listesi yine açılmalı.
        Http::fake(['*/api_jsonrpc.php' => Http::response($this->zabbixFixture('error'))]);

        $switch = NetworkSwitch::factory()->create(['hostname' => 'YINE-GORUNMELI']);
        SwitchPort::factory()->for($switch, 'switch')->create();

        $this->actingAs($this->reader())
            ->get(route('overview'))
            ->assertOk()
            ->assertSee('YINE-GORUNMELI')
            ->assertSee('İzleme sisteminden veri alınamadı.');
    }

    public function test_the_page_survives_a_connection_failure(): void
    {
        Http::fake(['*/api_jsonrpc.php' => fn () => throw new ConnectionException('bağlantı yok')]);

        NetworkSwitch::factory()->create(['hostname' => 'HALA-GORUNMELI']);

        $this->actingAs($this->reader())
            ->get(route('overview'))
            ->assertOk()
            ->assertSee('HALA-GORUNMELI');
    }

    public function test_recent_activity_is_hidden_from_users_without_log_access(): void
    {
        $this->fakeZabbix();
        ActivityLog::factory()->create(['description' => 'GIZLI HAREKET']);

        $this->actingAs($this->reader())
            ->get(route('overview'))
            ->assertOk()
            ->assertDontSee('GIZLI HAREKET');
    }

    public function test_an_admin_sees_recent_activity(): void
    {
        $this->fakeZabbix();
        ActivityLog::factory()->create(['description' => 'GORUNUR HAREKET']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('overview'))
            ->assertOk()
            ->assertSee('GORUNUR HAREKET');
    }

    /**
     * SAYAÇLARIN TOPLAMI SWITCH SAYISINI VERİR.
     *
     * "Bilinmiyor" durumu da bir kutuda sayılmalı; sayılmazsa durumu
     * bilinmeyen switch sayaçlardan sessizce kaybolur.
     */
    public function test_sayaclarin_toplami_switch_sayisini_verir(): void
    {
        $this->fakeZabbix();

        NetworkSwitch::factory()->create(['status' => SwitchStatus::Online]);
        NetworkSwitch::factory()->create(['status' => SwitchStatus::Warning]);
        NetworkSwitch::factory()->create(['status' => SwitchStatus::Offline]);
        NetworkSwitch::factory()->create(['status' => SwitchStatus::Unknown]);

        $sayac = app(NetworkOverviewService::class)
            ->forUser(User::factory()->admin()->create())['counts'];

        $this->assertSame(4, $sayac['total']);
        $this->assertSame(
            $sayac['total'],
            $sayac['online'] + $sayac['warning'] + $sayac['offline'] + $sayac['unknown'],
            'Her switch bir kutuda sayılmalı.',
        );
    }

    /**
     * ÖLÇÜLEMEYEN CİHAZ "SAĞLIKLI" DEĞİLDİR.
     *
     * Ölçüm alınamayan cihaz varken başlık "Tüm switchler sağlıklı"
     * dememeli. Yine de "çevrimdışı" denmez: ölçüm alamamak cihazın
     * düştüğünü kanıtlamaz.
     */
    public function test_olculemeyen_switch_varken_hepsi_saglikli_denmez(): void
    {
        $this->fakeZabbix();

        NetworkSwitch::factory()->create(['status' => SwitchStatus::Online]);
        NetworkSwitch::factory()->create(['status' => SwitchStatus::Unknown]);

        $saglik = app(NetworkOverviewService::class)
            ->forUser(User::factory()->admin()->create())['health'];

        $this->assertSame('Ölçülemeyen switch var', $saglik['label']);
        $this->assertNotSame('green', $saglik['tone']);
    }
}
