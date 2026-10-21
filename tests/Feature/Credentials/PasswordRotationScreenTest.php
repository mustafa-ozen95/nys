<?php

declare(strict_types=1);

namespace Tests\Feature\Credentials;

use App\Enums\CredentialProtocol;
use App\Enums\PasswordRotationStage;
use App\Enums\PasswordRotationStatus;
use App\Jobs\RotateSwitchPasswordJob;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Toplu parola değişimi ekranı.
 *
 * Yalnızca yönetici: bu ekran panelin bütün cihazlara erişimini tek tuşla
 * değiştirir. "Cihaza yaz" yetkisi bunu kapsamaz.
 */
final class PasswordRotationScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_ekran_cihazlari_ve_akisi_gosterir(): void
    {
        $switch = $this->switch();

        $this->actingAs($this->admin())
            ->get(route('settings.passwords'))
            ->assertOk()
            ->assertSee('Toplu parola değişimi')
            ->assertSee($switch->hostname)
            ->assertSee('kurtarma hesabı açılır', false);
    }

    public function test_ayarlar_seridinde_gorunur(): void
    {
        $this->actingAs($this->admin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee(route('settings.passwords'));
    }

    public function test_toplu_degisim_her_switch_icin_is_kuyruga_alir(): void
    {
        Queue::fake();

        $bir = $this->switch();
        $iki = $this->switch('test2', '198.18.20.196');

        // Kimliği olmayan switch kuyruğa alınmaz; parolası değiştirilemez.
        NetworkSwitch::factory()->create(['hostname' => 'kimliksiz']);

        $this->actingAs($this->admin())
            ->post(route('settings.passwords.store'))
            ->assertRedirect()
            ->assertSessionHas('status');

        /*
         * TEK iş gönderilir; diğerleri ona ZİNCİRLENİR. Cihazlar sırayla
         * işlensin diye: tur cihazda altı commit atıyor ve dördü aynı anda
         * koşunca hepsi birden işçi zamanaşımına girer.
         */
        Queue::assertPushed(RotateSwitchPasswordJob::class, 1);
        Queue::assertPushed(
            RotateSwitchPasswordJob::class,
            fn (RotateSwitchPasswordJob $job): bool => $job->switchId === $bir->id
                && $this->zincirdekiler($job) === [$iki->id],
        );
    }

    /**
     * Zincire eklenmiş işlerin switch kimlikleri, SIRASIYLA.
     *
     * @return list<int>
     */
    private function zincirdekiler(RotateSwitchPasswordJob $job): array
    {
        return array_map(
            fn (string $seri): int => unserialize($seri)->switchId,
            $job->chained,
        );
    }

    public function test_tek_switch_kuyruga_alinir(): void
    {
        Queue::fake();

        $switch = $this->switch();

        $this->actingAs($this->admin())
            ->put(route('settings.passwords.update', $switch))
            ->assertRedirect()
            ->assertSessionHas('status');

        Queue::assertPushed(RotateSwitchPasswordJob::class, 1);
    }

    /**
     * Cihaza yazabilen ama yönetici olmayan biri bu ekrana giremez: burası
     * panelin bütün cihazlara erişimini değiştirir.
     */
    public function test_yonetici_olmayan_giremez(): void
    {
        Queue::fake();

        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'update', 'configure']);
        $this->grantDeviceWrite($user);
        $user = $user->fresh();

        $this->actingAs($user)->get(route('settings.passwords'))->assertForbidden();
        $this->actingAs($user)->post(route('settings.passwords.store'))->assertForbidden();
        $this->actingAs($user)->put(route('settings.passwords.update', $switch))->assertForbidden();

        Queue::assertNothingPushed();
    }

    /**
     * Kurtarma hesabı kalmış turlar en üstte, uyarı olarak durur: cihazda
     * tam yetkili açık bir hesap var demektir.
     */
    public function test_kalmis_kurtarma_hesabi_ekranda_uyari_verir(): void
    {
        $switch = $this->switch();

        $switch->passwordRotations()->create([
            'status' => PasswordRotationStatus::Failed,
            'stage' => PasswordRotationStage::PasswordChanged,
            'recovery_username' => 'nsm-kurtarma',
            'recovery_password' => 'gizliKurtarma9',
            'recovery_active' => true,
            'error' => 'yeni parola denenemedi',
        ]);

        $this->actingAs($this->admin())
            ->get(route('settings.passwords'))
            ->assertOk()
            ->assertSee('Kapatılmamış kurtarma hesapları')
            // Kurtarma parolası hiçbir ekranda basılmaz.
            ->assertDontSee('gizliKurtarma9');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function switch(string $hostname = 'test1', string $ip = '198.18.20.195'): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => $hostname, 'management_ip' => $ip]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'eskiparola',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
