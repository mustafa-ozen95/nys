<?php

declare(strict_types=1);

namespace Tests\Feature\Remote;

use App\Enums\ActivityChannel;
use App\Enums\ServerStatus;
use App\Models\ActivityLog;
use App\Models\NetworkSwitch;
use App\Models\Server;
use App\Models\SwitchCredential;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Remote\RemoteSessionStore;
use App\Services\Remote\RemoteTarget;
use App\Services\Remote\TtydManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bu testler gerçek bir ttyd süreci başlatmaz ve hiçbir switche bağlanmaz:
 * TtydManager sahte bir uygulamayla değiştirilir.
 */
final class RemoteSessionTest extends TestCase
{
    use RefreshDatabase;

    private array $baslatilanlar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->baslatilanlar = [];

        config([
            'remote.guacamole.url' => 'https://guacamole.test/guacamole',
            'remote.guacamole.secret' => str_repeat('ab', 16),
        ]);

        // Guacamole'a gerçek istek gitmez.
        Http::fake([
            '*/api/tokens' => Http::response(['authToken' => 'sahte-jeton', 'dataSource' => 'json']),
        ]);

        $this->app->instance(TtydManager::class, new class($this->baslatilanlar) extends TtydManager
        {
            public function __construct(public array &$kayit)
            {
                // Üst sınıfın bağımlılığı kasıtlı olarak kurulmaz: bu sahte
                // uygulama hiçbir süreç başlatmaz.
            }

            public function start(RemoteTarget $target, string $token): array
            {
                $this->kayit[] = ['host' => $target->host, 'token' => $token];

                return ['port' => 7700, 'path' => '/ssh-proxy/7700/'];
            }

            public function stop(int $port): void
            {
                $this->kayit[] = ['stopped' => $port];
            }
        });
    }

    private function operator(array $actions = ['view', 'connect']): User
    {
        $user = User::factory()->create();
        $resolver = app(UserPermissionResolver::class);

        foreach (['switches', 'servers'] as $resource) {
            $resolver->syncResource($user, $resource, $actions);
        }

        return $user->fresh();
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'sw-test-1', 'management_ip' => '198.18.30.39']);
        SwitchCredential::factory()->for($switch, 'switch')->create(['username' => 'admin', 'password' => 'gizli']);

        return $switch;
    }

    public function test_connecting_requires_the_connect_permission(): void
    {
        $switch = $this->switch();

        $this->actingAs($this->operator(['view']))
            ->postJson(route('ajax.remote.connect'), ['type' => 'switch', 'id' => $switch->uuid])
            ->assertForbidden();

        $this->assertSame([], $this->baslatilanlar);
    }

    public function test_a_permitted_user_opens_a_session(): void
    {
        $switch = $this->switch();

        $this->actingAs($this->operator())
            ->postJson(route('ajax.remote.connect'), ['type' => 'switch', 'id' => $switch->uuid])
            ->assertOk()
            ->assertJsonStructure(['token', 'label', 'protocol', 'url'])
            ->assertJson(['label' => 'sw-test-1', 'protocol' => 'ssh']);

        $this->assertSame('198.18.30.39', $this->baslatilanlar[0]['host']);
    }

    public function test_opening_a_session_is_recorded(): void
    {
        $switch = $this->switch();

        $this->actingAs($this->operator())
            ->postJson(route('ajax.remote.connect'), ['type' => 'switch', 'id' => $switch->uuid]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'session_opened',
            'channel' => ActivityChannel::Ssh->value,
        ]);
    }

    public function test_closing_a_session_is_recorded(): void
    {
        $switch = $this->switch();
        $user = $this->operator();

        $token = $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['type' => 'switch', 'id' => $switch->uuid])
            ->json('token');

        $this->actingAs($user)
            ->postJson(route('ajax.remote.disconnect'), ['token' => $token])
            ->assertOk()
            ->assertJson(['closed' => true]);

        // Oturumun ne zaman ve kim tarafından kapatıldığı da kayda geçer.
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'session_closed',
            'channel' => ActivityChannel::Ssh->value,
            'user_id' => $user->id,
            'subject_type' => $switch->getMorphClass(),
            'subject_id' => $switch->id,
        ]);
    }

    /**
     * Tanınmayan jeton için oturum YOKTUR.
     *
     * 404 döner: "var ama senin değil" ile "hiç yok" arasındaki farkı
     * söylemek, elinde jeton olan birine bilgi vermek olurdu.
     */
    public function test_closing_an_unknown_session_records_nothing(): void
    {
        $this->actingAs($this->operator())
            ->postJson(route('ajax.remote.disconnect'), ['token' => 'olmayan-jeton'])
            ->assertNotFound();

        $this->assertDatabaseMissing('activity_logs', ['action' => 'session_closed']);
    }

    public function test_closing_a_session_twice_records_it_once(): void
    {
        $switch = $this->switch();
        $user = $this->operator();

        $token = $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['type' => 'switch', 'id' => $switch->uuid])
            ->json('token');

        // Kapatma düğmesi ve sekme kapanışı aynı jetonu iki kez gönderebilir.
        $this->actingAs($user)->postJson(route('ajax.remote.disconnect'), ['token' => $token]);
        $this->actingAs($user)->postJson(route('ajax.remote.disconnect'), ['token' => $token]);

        $this->assertSame(1, ActivityLog::query()->where('action', 'session_closed')->count());
    }

    public function test_a_switch_without_credentials_cannot_be_connected_to(): void
    {
        $switch = NetworkSwitch::factory()->create();

        $this->actingAs($this->operator())
            ->postJson(route('ajax.remote.connect'), ['type' => 'switch', 'id' => $switch->uuid])
            ->assertForbidden();

        $this->assertSame([], $this->baslatilanlar);
    }

    public function test_an_offline_server_cannot_be_connected_to(): void
    {
        $server = Server::factory()->offline()->create();

        $this->actingAs($this->operator())
            ->postJson(route('ajax.remote.connect'), ['type' => 'server', 'id' => $server->uuid])
            ->assertForbidden();
    }

    public function test_an_online_ssh_server_uses_the_browser_terminal(): void
    {
        $server = Server::factory()->create(['status' => ServerStatus::Online, 'ip_address' => '192.0.2.50']);

        $this->actingAs($this->operator())
            ->postJson(route('ajax.remote.connect'), ['type' => 'server', 'id' => $server->uuid])
            ->assertOk()
            ->assertJson(['protocol' => 'ssh']);

        $this->assertSame('192.0.2.50', $this->baslatilanlar[0]['host']);
    }

    public function test_an_rdp_server_goes_to_guacamole_not_the_ssh_terminal(): void
    {
        $server = Server::factory()->windows()->create([
            'status' => ServerStatus::Online,
            'ip_address' => '198.18.20.16',
        ]);

        $this->actingAs($this->operator())
            ->postJson(route('ajax.remote.connect'), ['type' => 'server', 'id' => $server->uuid])
            ->assertOk()
            ->assertJson(['protocol' => 'rdp']);

        // RDP hedefine SSH terminali açılmamalı: eski davranış buydu.
        $this->assertSame([], $this->baslatilanlar);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/tokens'));
    }

    public function test_a_missing_guacamole_secret_is_reported_clearly(): void
    {
        config(['remote.guacamole.secret' => '']);

        $server = Server::factory()->windows()->create(['status' => ServerStatus::Online]);

        $this->actingAs($this->operator())
            ->postJson(route('ajax.remote.connect'), ['type' => 'server', 'id' => $server->uuid])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Guacamole gizli anahtarı tanımlı değil.');
    }

    public function test_the_session_page_opens_for_its_owner(): void
    {
        $switch = $this->switch();
        $user = $this->operator();

        $token = $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['type' => 'switch', 'id' => $switch->uuid])
            ->json('token');

        $this->actingAs($user)
            ->get(route('remote.session', ['token' => $token]))
            ->assertOk()
            ->assertSee('sw-test-1');
    }

    public function test_the_session_page_refuses_an_unknown_token(): void
    {
        $this->actingAs($this->operator())
            ->get(route('remote.session', ['token' => 'olmayan-jeton']))
            ->assertNotFound();
    }

    public function test_the_session_page_refuses_a_user_whose_permission_was_revoked(): void
    {
        $switch = $this->switch();
        $user = $this->operator();

        $token = $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['type' => 'switch', 'id' => $switch->uuid])
            ->json('token');

        // Oturum açıldıktan sonra yetki geri alınırsa sayfa da kapanmalı.
        app(UserPermissionResolver::class)
            ->syncResource($user, 'switches', ['view']);

        $this->actingAs($user->fresh())
            ->get(route('remote.session', ['token' => $token]))
            ->assertForbidden();
    }

    public function test_the_switch_page_offers_a_connect_button_only_when_reachable(): void
    {
        $baglanilir = $this->switch();
        $kimliksiz = NetworkSwitch::factory()->create(['hostname' => 'KIMLIKSIZ']);

        $this->actingAs($this->operator())
            ->get(route('switches.show', $baglanilir))
            ->assertOk()
            ->assertSee('data-uzak-baglan', escape: false)
            ->assertSee('Bağlan');

        // Kimlik bilgisi olmayan switche bağlanılamaz, düğme de çıkmaz.
        $this->actingAs($this->operator())
            ->get(route('switches.show', $kimliksiz))
            ->assertOk()
            ->assertDontSee('data-uzak-baglan', escape: false);
    }

    public function test_an_unknown_target_type_is_rejected(): void
    {
        $this->actingAs($this->operator())
            ->postJson(route('ajax.remote.connect'), ['type' => 'kullanici', 'id' => 1])
            ->assertStatus(422);
    }

    public function test_a_session_can_be_kept_alive_and_closed(): void
    {
        $switch = $this->switch();
        $user = $this->operator();

        $token = $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['type' => 'switch', 'id' => $switch->uuid])
            ->json('token');

        $this->actingAs($user)
            ->postJson(route('ajax.remote.keep-alive'), ['token' => $token])
            ->assertOk()
            ->assertJson(['alive' => true]);

        $this->actingAs($user)
            ->postJson(route('ajax.remote.disconnect'), ['token' => $token])
            ->assertOk();

        $this->assertNull(app(RemoteSessionStore::class)->get($token));
        $this->assertContains(['stopped' => 7700], $this->baslatilanlar);
    }

    public function test_keeping_an_unknown_session_alive_reports_it_as_dead(): void
    {
        $this->actingAs($this->operator())
            ->postJson(route('ajax.remote.keep-alive'), ['token' => 'olmayan-oturum'])
            ->assertOk()
            ->assertJson(['alive' => false]);
    }

    public function test_a_guest_cannot_open_a_session(): void
    {
        $this->postJson(route('ajax.remote.connect'), ['type' => 'switch', 'id' => 1])
            ->assertUnauthorized();
    }

    /**
     * BAŞKASININ OTURUMUNA DOKUNULAMAZ.
     *
     * Jeton tek başına kimlik değildir: öyle olsaydı eline geçiren biri
     * oturumu kapatabilir, yetkisi geri alınmış biri de kendi penceresinden
     * süreci süresiz canlı tutabilirdi.
     */
    public function test_baskasinin_oturumu_kapatilamaz_ve_uzatilamaz(): void
    {
        $switch = $this->switch();
        $sahibi = $this->operator();

        $jeton = $this->actingAs($sahibi)
            ->postJson(route('ajax.remote.connect'), [
                'type' => 'switch',
                'id' => $switch->uuid,
            ])
            ->assertOk()
            ->json('token');

        $baskasi = $this->operator();

        $this->actingAs($baskasi)
            ->postJson(route('ajax.remote.keep-alive'), ['token' => $jeton])
            ->assertOk()
            ->assertJson(['alive' => false]);

        $this->actingAs($baskasi)
            ->postJson(route('ajax.remote.disconnect'), ['token' => $jeton])
            ->assertNotFound();

        /* Sahibi hâlâ kullanabiliyor. */
        $this->actingAs($sahibi)
            ->postJson(route('ajax.remote.keep-alive'), ['token' => $jeton])
            ->assertOk()
            ->assertJson(['alive' => true]);
    }
}
