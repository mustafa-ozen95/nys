<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\SwitchRole;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Cihaz saati ve NTP açılış sunucusu.
 *
 * NTP sunucusu tanımlı olsa bile cihaz saati yıllarca geride kalabilir;
 * saat yanlışsa commit geçmişi ve günlük sıralaması yanıltıcı olur. İki
 * katmanlı sebep:
 *
 *  1. Cihazın NTP sunucusuna yolu yoksa ("No route to host") saat hiç
 *     eşitlenmez.
 *  2. Erişilse bile "ntp server" tek başına düzeltmez: ntpd fark 1000
 *     saniyeyi aşınca saati adımlamayı reddeder.
 *
 * Panel bu yüzden sapmayı gösterir.
 */
final class DeviceClockTest extends TestCase
{
    use RefreshDatabase;

    public function test_sapma_esigi_asinca_uyari_cikar(): void
    {
        $switch = $this->switch();
        $switch->forceFill([
            'clock_offset_seconds' => 315_000_000,
            'clock_checked_at' => now(),
        ])->save();

        $this->actingAs($this->operator())
            ->get(route('switches.settings', $switch))
            ->assertOk()
            ->assertSee('Cihazın saati panelden');
    }

    /**
     * Küçük sapma uyarı DEĞİLDİR: her ekrana uyarı koymak uyarıyı
     * değersizleştirir.
     */
    public function test_kucuk_sapma_uyari_uretmez(): void
    {
        $switch = $this->switch();
        $switch->forceFill([
            'clock_offset_seconds' => 3,
            'clock_checked_at' => now(),
        ])->save();

        $this->actingAs($this->operator())
            ->get(route('switches.settings', $switch))
            ->assertOk()
            ->assertDontSee('Cihazın saati panelden');
    }

    public function test_hic_olculmemisse_uyari_cikmaz(): void
    {
        $this->actingAs($this->operator())
            ->get(route('switches.settings', $this->switch()))
            ->assertOk()
            ->assertDontSee('Cihazın saati panelden');
    }

    /**
     * "boot-server" cihaza yazılır; "server" ile aynı düğümde durur.
     */
    public function test_acilis_sunucusu_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.device-settings', $switch), [
                'hostname' => $switch->hostname,
                'ntp_servers' => "10.0.0.1\n",
                'ntp_boot_server' => '10.0.0.1',
                'version' => $switch->updated_at?->timestamp,
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<boot-server>10.0.0.1</boot-server>', $device->payload());
    }

    /**
     * Boş bırakmak satırı SİLER; cihazda kalıntı bırakmaz.
     */
    public function test_acilis_sunucusu_bos_birakilinca_silinir(): void
    {
        $switch = $this->switch();
        $switch->forceFill(['ntp_boot_server' => '10.0.0.1'])->save();

        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.device-settings', $switch), [
                'hostname' => $switch->hostname,
                'ntp_servers' => "10.0.0.1\n",
                'ntp_boot_server' => '',
                'version' => $switch->updated_at?->timestamp,
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<boot-server delete="delete"/>', $device->payload());
    }

    public function test_gecersiz_acilis_sunucusu_reddedilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.device-settings', $switch), [
                'hostname' => $switch->hostname,
                'ntp_boot_server' => 'sunucu.example',
                'version' => $switch->updated_at?->timestamp,
            ])
            ->assertSessionHasErrors('ntp_boot_server');

        $this->assertNull($device->connection);
    }

    /**
     * NTP sunucusu hiç tanımlı değilse saat neyle eşitlenecek belli değildir;
     * panel cihaza gitmeden söyler.
     */
    public function test_ntp_sunucusu_yoksa_saat_esitlenemez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.device-settings.clock', $switch))
            ->assertSessionHas('error');

        $this->assertNull($device->connection, 'Sunucu yokken cihaza bağlanıldı.');
    }

    public function test_saat_esitleme_yetki_ister(): void
    {
        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        $this->actingAs($user->fresh())
            ->post(route('switches.device-settings.clock', $switch))
            ->assertForbidden();
    }

    /**
     * OMURGALAR LİSTEDE ÜSTTE.
     *
     * Bir omurga kesintisi altındaki bütün erişim switchlerini etkiler;
     * listeye bakan kişi önce onu görmeli. Rol birinci, durum ikinci
     * anahtardır: sorunlu omurga sağlıklı omurgaların da üstünde kalır.
     */
    public function test_omurgalar_listede_ustte_durur(): void
    {
        NetworkSwitch::factory()->create(['hostname' => 'aaa-erisim', 'role' => SwitchRole::Access]);
        NetworkSwitch::factory()->create(['hostname' => 'zzz-omurga', 'role' => SwitchRole::Core]);

        $sira = $this->actingAs($this->operator())
            ->get(route('switches.index'))
            ->assertOk()
            ->viewData('switches')
            ->pluck('hostname');

        $this->assertSame('zzz-omurga', $sira->first(), 'Omurga listenin başında değil.');
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice('[edit system]');
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }
}
