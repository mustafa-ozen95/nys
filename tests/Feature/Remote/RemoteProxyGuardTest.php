<?php

declare(strict_types=1);

namespace Tests\Feature\Remote;

use App\Enums\UserStatus;
use App\Models\NetworkSwitch;
use App\Models\Server;
use App\Models\SwitchCredential;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Remote\RemoteSessionStore;
use App\Services\Remote\TtydManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ttyd portları yalnızca nginx üzerinden erişilebilir ve nginx her isteği
 * bu uca sorar. Bekçi olmadan açık bir oturum sırasında port aralığını
 * tarayan biri terminale düşebilirdi.
 */
final class RemoteProxyGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $baslatilanlar = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/api/tokens' => Http::response(['authToken' => 'sahte-jeton', 'dataSource' => 'json']),
        ]);

        /*
         * Guacamole anahtarı SINAMADA verilir.
         *
         * Gerçek değeri ortamdan geliyor ve testler onu kurmadığında RDP
         * oturumu "anahtar tanımlı değil" diyerek 409 dönüyordu: sınama,
         * koşturan makinenin ortam değişkenine bağlı kalmıştı — kiminde
         * geçiyor, kiminde geçmiyordu (temiz ortamda
         * test_the_rdp_proxy_is_checked_without_a_port kırılır).
         */
        config(['remote.guacamole.secret' => str_repeat('ab', 16)]);

        // Gerçek ttyd süreci başlatılmaz.
        $this->mock(TtydManager::class, function ($mock): void {
            $mock->shouldReceive('start')->andReturnUsing(function ($target, string $token): array {
                $port = 7700 + count($this->baslatilanlar);
                $this->baslatilanlar[] = ['port' => $port];

                // Gerçek yönetici portu oturuma ayırır; bekçi bu eşlemeyi okur.
                app(RemoteSessionStore::class)->reservePort($port, $token);

                return ['port' => $port, 'path' => "/ssh-proxy/{$port}/"];
            });
            $mock->shouldReceive('stop')->andReturnNull();
        });
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $resolver = app(UserPermissionResolver::class);

        foreach (['switches', 'servers'] as $resource) {
            $resolver->syncResource($user, $resource, ['view', 'connect']);
        }

        return $user->fresh();
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create();
        SwitchCredential::factory()->for($switch, 'switch')->create(['password' => 'gizli']);

        return $switch;
    }

    private function oturumAc(User $user, NetworkSwitch $switch): int
    {
        $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['type' => 'switch', 'id' => $switch->uuid]);

        return $this->baslatilanlar[count($this->baslatilanlar) - 1]['port'];
    }

    public function test_the_owner_is_allowed_through(): void
    {
        $user = $this->operator();
        $port = $this->oturumAc($user, $this->switch());

        $this->actingAs($user)
            ->get(route('ajax.remote.guard', ['port' => $port]))
            ->assertNoContent();
    }

    public function test_a_port_with_no_session_is_refused(): void
    {
        $this->actingAs($this->operator())
            ->get(route('ajax.remote.guard', ['port' => 7899]))
            ->assertForbidden();
    }

    public function test_another_user_cannot_reach_someone_elses_session(): void
    {
        $port = $this->oturumAc($this->operator(), $this->switch());

        $this->actingAs($this->operator())
            ->get(route('ajax.remote.guard', ['port' => $port]))
            ->assertForbidden();
    }

    public function test_a_guest_is_refused(): void
    {
        // nginx yalnızca 401/403 anlar; yönlendirme 500'e dönüşürdü.
        $this->get(route('ajax.remote.guard', ['port' => 7700]))
            ->assertUnauthorized();
    }

    public function test_access_is_refused_once_the_permission_is_revoked(): void
    {
        $user = $this->operator();
        $switch = $this->switch();
        $port = $this->oturumAc($user, $switch);

        // Oturum açıkken yetki geri alınırsa proxy de kapanır.
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        $this->actingAs($user->fresh())
            ->get(route('ajax.remote.guard', ['port' => $port]))
            ->assertForbidden();
    }

    /**
     * Hesap kapatılınca AÇIK DURAN terminal de kapanmalı.
     *
     * Bu uç "auth" katmanının dışında (nginx auth_request yönlendirmeyi
     * anlamıyor), dolayısıyla EnsureUserIsActive buraya hiç uğramıyor.
     * Uç ayrıca bakmazsa pasifleştirilmiş kullanıcı panelde her sayfada
     * oturumdan atılırken elindeki terminal çalışmaya devam eder.
     */
    public function test_pasif_hesap_acik_terminale_erisemez(): void
    {
        $user = $this->operator();
        $port = $this->oturumAc($user, $this->switch());

        $user->forceFill(['status' => UserStatus::Inactive])->save();

        $this->actingAs($user->fresh())
            ->get(route('ajax.remote.guard', ['port' => $port]))
            ->assertForbidden();
    }

    public function test_the_port_is_read_from_the_original_uri(): void
    {
        $user = $this->operator();
        $port = $this->oturumAc($user, $this->switch());

        // nginx alt isteği portu sorgu parametresiyle değil, istemcinin
        // özgün adresini taşıyan başlıkla bildirir.
        $this->actingAs($user)
            ->withHeader('X-Original-Uri', "/ssh-proxy/{$port}/")
            ->get(route('ajax.remote.guard'))
            ->assertNoContent();

        $this->actingAs($user)
            ->withHeader('X-Original-Uri', '/ssh-proxy/7899/')
            ->get(route('ajax.remote.guard'))
            ->assertForbidden();
    }

    public function test_the_rdp_proxy_is_checked_without_a_port(): void
    {
        $user = $this->operator();
        $server = Server::factory()->windows()->create(['connection_password' => 'gizli']);

        $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['type' => 'server', 'id' => $server->uuid])
            ->assertOk();

        $this->actingAs($user)->get(route('ajax.remote.guard'))->assertNoContent();

        // Açık RDP oturumu olmayan kullanıcı geçemez.
        $this->actingAs($this->operator())->get(route('ajax.remote.guard'))->assertForbidden();
    }

    /**
     * Ham adres ile nginx'in normalleştirdiği adres ayrışmamalı.
     * Saldırgan kendi portunu önce yazıp ".." ile kurbanın portuna iner;
     * bekçi kendi portunu yetkilendirir, nginx kurbanınkini sunardı.
     */
    public function test_dot_segment_and_encoded_paths_never_authorise_another_port(): void
    {
        $kurban = $this->operator();
        $kurbanPort = $this->oturumAc($kurban, $this->switch());
        $saldirgan = $this->operator();
        $kendiPort = $this->oturumAc($saldirgan, $this->switch());

        $this->assertNotSame($kurbanPort, $kendiPort);

        foreach ([
            "/ssh-proxy/{$kendiPort}/../{$kurbanPort}/",
            "/ssh-proxy/{$kendiPort}/..%2f{$kurbanPort}/",
            "/ssh-proxy/{$kendiPort}/%2e%2e/{$kurbanPort}/",
            "/ssh-proxy/{$kendiPort}//../{$kurbanPort}/",
            "/ssh-proxy/{$kendiPort}/.\\..\\{$kurbanPort}/",
            "/rdp-proxy/../ssh-proxy/{$kurbanPort}/",
            "/ssh-proxy/{$kendiPort}/../../ssh-proxy/{$kurbanPort}/?x=1",
        ] as $adres) {
            $this->actingAs($saldirgan)
                ->withHeader('X-Original-Uri', $adres)
                ->get(route('ajax.remote.guard'))
                ->assertForbidden();
        }

        // Kurbanın portu doğrudan da saldırgana kapalı; kendi portu açık.
        $this->actingAs($saldirgan)
            ->withHeader('X-Original-Uri', "/ssh-proxy/{$kurbanPort}/")
            ->get(route('ajax.remote.guard'))
            ->assertForbidden();

        $this->actingAs($saldirgan)
            ->withHeader('X-Original-Uri', "/ssh-proxy/{$kendiPort}/ws?arg=1")
            ->get(route('ajax.remote.guard'))
            ->assertNoContent();
    }
}
