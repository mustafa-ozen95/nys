<?php

declare(strict_types=1);

namespace Tests\Feature\Credentials;

use App\Enums\CredentialProtocol;
use App\Jobs\RotateSwitchPasswordJob;
use App\Jobs\SyncSwitchNetconfJob;
use App\Models\ActivityLog;
use App\Models\NetworkSwitch;
use App\Models\SwitchCredential;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Credentials\SwitchPasswordRotator;
use App\Services\Netconf\DeviceSettingsSynchronizer;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeJunosAccounts;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Elle verilen cihaz parolası.
 *
 * İki iş, bilinçli olarak AYRI:
 *
 *   record — cihazda ZATEN geçerli olan parolayı yalnızca panele kaydeder.
 *            Panel dışında kurulmuş bir switchin parolası böyle okunabilir
 *            hâle gelir. Parola ÖNCE denenir; yanlışsa kaydedilmez.
 *   apply  — parolayı cihaza yazar. Rastgele turla birebir aynı güvenli
 *            sıradan geçer; elle seçilmiş olması onu daha az tehlikeli
 *            yapmaz.
 *
 * Panel parolayı cihazdan HİÇ okumaz — cihazda yalnızca özeti var ve özet
 * okunsaydı ekranda okunamayan bir değer görünürdü.
 */
final class ManualPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['credentials.rotation.recovery_username' => 'nsm-kurtarma']);
    }

    public function test_elle_verilen_parola_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        app(SwitchPasswordRotator::class)->rotate($switch, null, 'ElleVerilen42');

        // Cihazdaki parola tam olarak verilen dizedir, üretilen değil.
        $this->assertSame('ElleVerilen42', $device->accounts()['admin']);
        $this->assertSame('ElleVerilen42', $switch->fresh()->netconfCredential->password);

        // Kurtarma hesabı yine açılıp silinmiş olmalı: güvenli sıra aynı.
        $this->assertArrayNotHasKey('nsm-kurtarma', $device->accounts());
        $this->assertStringContainsString('<name>nsm-kurtarma</name>', $device->payload());
    }

    public function test_parola_etkinlik_kaydina_dusmez(): void
    {
        $switch = $this->switch();
        $this->device();

        app(SwitchPasswordRotator::class)->rotate($switch, null, 'ElleVerilen42');

        $this->assertSame(
            0,
            ActivityLog::query()->where('description', 'like', '%ElleVerilen42%')->count(),
        );
        $this->assertSame(
            1,
            ActivityLog::query()->where('description', 'like', '%parola degistirildi%')->count(),
        );
    }

    /**
     * PANEL PAROLAYI CİHAZDAN OKUMAZ.
     *
     * Cihazda parolanın yalnızca ÖZETİ var ("$1$...", geri çevrilemez).
     * Eşitleme onu okuyup kimlik kaydının üstüne yazsaydı ekranda okunamayan
     * bir değer görünür ve panel bir daha cihaza bağlanamazdı.
     *
     * Bu test o yolun hiç açılmadığını kilitler: eşitleme cihazın parola
     * özetini görse bile paneldeki DÜZ parola olduğu gibi kalır.
     */
    public function test_esitleme_paneldeki_parolayi_ezmez(): void
    {
        $switch = $this->switch();

        $device = new FakeJunosDevice(
            interfaceExtras: '<system><login>'
                .'<user><name>admin</name><uid>200</uid><class>super-user</class>'
                .'<authentication><encrypted-password>$1$cihazOzeti$asla</encrypted-password></authentication>'
                .'</user></login></system>',
        );

        $this->app->instance(NetconfTransport::class, $device);

        app(DeviceSettingsSynchronizer::class);

        /*
         * Bağımlılıklar KAPSAYICIDAN çözülür, tek tek sayılmaz: işe yeni bir
         * eşitleyici eklendiğinde (fırtına denetimi, VoIP, port aynalama...)
         * bu çağrı sessizce bozuluyor ve testin konusuyla ilgisi olmayan bir
         * TypeError veriyordu.
         */
        $this->app->call([new SyncSwitchNetconfJob($switch->id, force: true), 'handle']);

        // Paneldeki DÜZ parola değişmedi; cihazın özeti hiçbir yere yazılmadı.
        $this->assertSame('eskiparola', $switch->fresh()->netconfCredential->password);

        $this->assertSame(
            0,
            SwitchCredential::query()->get()
                ->filter(fn ($c): bool => str_contains((string) $c->password, '$1$'))
                ->count(),
        );
    }

    public function test_ucu_cihaza_yazmayi_kuyruga_alir(): void
    {
        Queue::fake();

        $switch = $this->switch();

        $this->actingAs($this->operator($switch))
            ->put(route('settings.passwords.manual', $switch), [
                'password' => 'ElleVerilen42',
                'password_confirmation' => 'ElleVerilen42',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Queue::assertPushed(
            RotateSwitchPasswordJob::class,
            fn (RotateSwitchPasswordJob $job): bool => $job->switchId === $switch->id
                && $job->password === 'ElleVerilen42',
        );
    }

    public function test_zayif_parola_reddedilir(): void
    {
        Queue::fake();

        $switch = $this->switch();

        foreach (['kisa1', 'yalnizkucukharf'] as $weak) {
            $this->actingAs($this->operator($switch))
                ->put(route('settings.passwords.manual', $switch), [
                    'password' => $weak,
                    'password_confirmation' => $weak,
                ])
                ->assertSessionHasErrors('password');
        }

        Queue::assertNothingPushed();
    }

    public function test_parola_yetkisi_olmayan_elle_veremez(): void
    {
        Queue::fake();

        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);

        $this->actingAs($user->fresh())
            ->put(route('settings.passwords.manual', $switch), [
                'password' => 'ElleVerilen42',
                'password_confirmation' => 'ElleVerilen42',
            ])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, string>  $accounts
     */
    private function device(array $accounts = ['admin' => 'eskiparola']): FakeJunosAccounts
    {
        $device = new FakeJunosAccounts($accounts);

        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function operator(NetworkSwitch $switch): User
    {
        $user = User::factory()->create();

        app(UserPermissionResolver::class)->syncResource(
            $user,
            'switches',
            ['view'],
            ['rotate' => [$switch->id], 'secret' => [$switch->id]],
        );

        return $user->fresh();
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'test1', 'management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'eskiparola',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
