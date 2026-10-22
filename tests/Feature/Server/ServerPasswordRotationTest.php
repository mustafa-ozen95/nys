<?php

declare(strict_types=1);

namespace Tests\Feature\Server;

use App\Enums\PasswordRotationStage;
use App\Enums\PasswordRotationStatus;
use App\Jobs\ServerPasswordRotateJob;
use App\Models\Server;
use App\Models\ServerPasswordRotation;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Server\Driver\ServerDriver;
use App\Services\Server\DriverSelector;
use App\Services\Server\ServerPasswordRotator;
use App\Services\Server\ServerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sunucu parolasının değiştirilmesi.
 *
 * NYS'nin sunucuları kendine aittir ve parolalarını kendisi değiştirir.
 * GÜVENLİĞİ VEREN ŞEY SIRANIN KENDİSİDİR — bu yüzden sınanan şey "parola
 * değişti mi" değil, sıranın adımlarının doğru yerde ve doğru kimlikle
 * yapıldığıdır.
 *
 * Makineye BAĞLANILMAZ: sürücü sahtelenir. Gerçek SSH/WinRM yolunun kendi
 * ölçümleri sürücülerin içinde yazılı.
 */
final class ServerPasswordRotationTest extends TestCase
{
    use RefreshDatabase;

    private function server(array $onto = []): Server
    {
        return Server::factory()->create([
            'short_name' => 'LNX-09',
            'ip_address' => '198.18.20.9',
            'os_type' => 'linux',
            'connection_protocol' => 'ssh',
            'connection_port' => 22,
            'connection_username' => 'root',
            'connection_password' => 'ESKI-PAROLA',
            'status' => 'online',
            ...$onto,
        ]);
    }

    /** Sürücüyü sahteleyip yerine koyar. */
    private function driverFake(ServerDriver $driver): void
    {
        $selector = new class($driver) extends DriverSelector
        {
            public function __construct(private ServerDriver $fake) {}

            public function for(Server $server): ServerDriver
            {
                return $this->fake;
            }

            public function engel(Server $server): ?string
            {
                return null;
            }
        };

        $this->app->instance(DriverSelector::class, $selector);
    }

    public function test_sira_kurtarma_hesabiyla_isler_ve_parola_kayda_yazilir(): void
    {
        $server = $this->server();

        $driver = new FakeDriver;
        $this->driverFake($driver);

        $round = app(ServerPasswordRotator::class)->rotate($server, null, 'yil sonu');

        $this->assertSame(PasswordRotationStatus::Succeeded, $round->status);
        $this->assertSame(PasswordRotationStage::RecoveryRemoved, $round->stage);

        /*
         * SIRA: kurtarma hesabı AÇILIR, kanıtlanır, parola KURTARMA HESABIYLA
         * değiştirilir, yeni parola denenir ve hesap en sonda silinir.
         * Parolasını değiştirdiğimiz hesapla değiştirmek, iş yarıda kalırsa
         * bizi dışarıda bırakırdı.
         */
        $this->assertSame(
            ['kurtarmaAc', 'parolaDegistir', 'kurtarmaSil'],
            $driver->done,
        );

        $this->assertNotSame('root', $driver->passwordChanger, 'Parola kendi oturumundan değiştirilmemeli.');
        $this->assertStringStartsWith('nys-kurtarma-', (string) $driver->passwordChanger);

        /* Kayıttaki parola ancak DOĞRULANDIKTAN sonra değişir. */
        $server->refresh();
        $this->assertNotSame('ESKI-PAROLA', $server->connection_password);
        $this->assertSame($driver->writtenPassword, $server->connection_password);

        /* Kurtarma hesabı silindi: paneldeki sırrı da silinmeli. */
        $this->assertFalse($round->recovery_active);
        $this->assertNull($round->recovery_password);
    }

    public function test_kurtarma_hesabi_dogrulanamazsa_tur_iptal_edilir(): void
    {
        $server = $this->server();

        $driver = new FakeDriver;
        $driver->recoveryVerified = false;
        $this->driverFake($driver);

        $round = app(ServerPasswordRotator::class)->rotate($server);

        $this->assertSame(PasswordRotationStatus::Failed, $round->status);

        /*
         * ÇALIŞMAYAN KURTARMA HESABI GÜVENCE DEĞİL, AÇIKTA DURAN FAZLADAN BİR
         * YETKİDİR: silinir ve asıl parolaya hiç dokunulmaz.
         */
        $this->assertContains('kurtarmaSil', $driver->done);
        $this->assertNotContains('parolaDegistir', $driver->done);

        $server->refresh();
        $this->assertSame('ESKI-PAROLA', $server->connection_password);
    }

    public function test_yeni_parola_dogrulanamazsa_kayit_degismez(): void
    {
        $server = $this->server();

        $driver = new FakeDriver;
        $driver->newPasswordVerified = false;
        $this->driverFake($driver);

        $round = app(ServerPasswordRotator::class)->rotate($server);

        $this->assertSame(PasswordRotationStatus::Failed, $round->status);

        /*
         * Kurtarma hesabı MAKİNEDE BIRAKILIR: makineye dönebileceğimiz kapı
         * açık kalmalı ve ekran bunu uyarı olarak göstermeli.
         */
        $this->assertTrue($round->recovery_active);
        $this->assertNotNull($round->recovery_password);

        $server->refresh();
        $this->assertSame('ESKI-PAROLA', $server->connection_password);
    }

    public function test_kurtarma_destegi_olmayan_platformda_dogrudan_degistirilir(): void
    {
        $server = $this->server(['os_type' => 'windows', 'connection_protocol' => 'rdp', 'connection_port' => 3389]);

        $driver = new FakeDriver;
        $driver->recovery = false;
        $this->driverFake($driver);

        $round = app(ServerPasswordRotator::class)->rotate($server);

        $this->assertSame(PasswordRotationStatus::Succeeded, $round->status);
        $this->assertSame(['parolaDegistir'], $driver->done);

        /* Parola kendi oturumundan değişir; başka kapı yok. */
        $this->assertSame('root', $driver->passwordChanger);

        $server->refresh();
        $this->assertSame($driver->writtenPassword, $server->connection_password);
    }

    public function test_yarida_kalan_tur_onarimda_kapatilir(): void
    {
        $server = $this->server();

        $round = ServerPasswordRotation::create([
            'server_id' => $server->id,
            'status' => PasswordRotationStatus::Failed,
            'stage' => PasswordRotationStage::RecoveryCreated,
            'recovery_username' => 'nys-kurtarma-abc123',
            'recovery_password' => 'KURTARMA-PAROLA',
            'recovery_active' => true,
        ]);

        $driver = new FakeDriver;
        $this->driverFake($driver);

        $result = app(ServerPasswordRotator::class)->repair($round->fresh());

        $this->assertSame(PasswordRotationStatus::Succeeded, $result->status);
        $this->assertFalse($result->recovery_active);
        $this->assertNull($result->recovery_password);
        $this->assertContains('kurtarmaSil', $driver->done);
    }

    public function test_yetkisi_olmayan_tur_baslatamaz(): void
    {
        $server = $this->server();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'servers', ['view', 'update']);

        $this->actingAs($user->fresh())
            ->post(route('servers.password', $server), ['reason' => 'denetim'])
            ->assertForbidden();
    }

    public function test_tur_kuyruga_alinir(): void
    {
        Queue::fake();

        $server = $this->server();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'servers', ['view', 'rotate']);

        $this->actingAs($user->fresh())
            ->post(route('servers.password', $server), ['reason' => 'denetim'])
            ->assertRedirect();

        Queue::assertPushed(ServerPasswordRotateJob::class);
    }
}

/**
 * Makineyle konuşmayan sürücü.
 *
 * Sıranın kendisini sınamak için yeterli: hangi işin hangi KİMLİKLE ve hangi
 * SIRAYLA yapıldığını kaydeder.
 */
final class FakeDriver implements ServerDriver
{
    /** @var list<string> */
    public array $done = [];

    public bool $recovery = true;

    public bool $recoveryVerified = true;

    public bool $newPasswordVerified = true;

    public ?string $passwordChanger = null;

    public ?string $writtenPassword = null;

    private ?string $recoveryName = null;

    public function engel(Server $server): ?string
    {
        return null;
    }

    public function recoverySupports(): bool
    {
        return $this->recovery;
    }

    public function open(Server $server, string $user, string $password): ServerSession
    {
        return new ServerSession(null, $user, $password, static fn () => null);
    }

    public function recoveryOpen(ServerSession $session, string $user, string $password): void
    {
        $this->done[] = 'kurtarmaAc';
        $this->recoveryName = $user;
    }

    public function passwordChange(ServerSession $session, string $user, string $newPassword): void
    {
        $this->done[] = 'parolaDegistir';
        $this->passwordChanger = $session->user;
        $this->writtenPassword = $newPassword;
    }

    public function recoveryDelete(ServerSession $session, string $user): void
    {
        $this->done[] = 'kurtarmaSil';
    }

    public function verify(Server $server, string $user, string $password): bool
    {
        if ($user === $this->recoveryName) {
            return $this->recoveryVerified;
        }

        /* Asıl hesap: yeni parola denenirken ayarlanabilir. */
        return $password === $this->writtenPassword
            ? $this->newPasswordVerified
            : true;
    }
}
