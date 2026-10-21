<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Enums\ActivityChannel;
use App\Enums\CredentialProtocol;
use App\Enums\PortMode;
use App\Enums\SwitchWriteStatus;
use App\Jobs\ApplySwitchWriteJob;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\SwitchWriteJob;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\NetconfException;
use App\Services\Netconf\StormControlConfigurator;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Cihaza yazma HTTP isteğini BEKLETMEZ.
 *
 * Bir yazma ELS switchte yaklaşık bir dakika sürebilir (iki commit, iki
 * oturum); istek o kadar tutulursa sayfa kilitlenir.
 *
 * Kuyruğa almanın bedeli var ve bu testler onu ödediğimizi kilitler:
 * kullanıcı, gerekçe ve cihazın FARKI kaybolmamalı; başarısızlık sessiz
 * kalmamalı; iş kör tekrar edilmemeli.
 */
final class DeviceWriteQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_yazma_istegi_cihazi_beklemez(): void
    {
        Queue::fake();

        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/9');
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.rstp.storm.store', $switch), [
                'interface_name' => 'ge-0/0/9',
                'level' => 80,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNull($device->connection, 'İstek cihaza bağlandı; kuyruğa alınmalıydı.');

        Queue::assertPushed(ApplySwitchWriteJob::class);

        $kayit = SwitchWriteJob::query()->firstOrFail();
        $this->assertSame(SwitchWriteStatus::Queued, $kayit->status);
        $this->assertSame($switch->id, $kayit->switch_id);
    }

    /**
     * İstek anındaki KİMLİK işe taşınır: kuyrukta istek yok ve kayıt
     * sahipsiz kalırdı.
     */
    public function test_gerekce_ve_kullanici_ise_tasinir(): void
    {
        Queue::fake();

        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/9');
        $this->device();
        $user = $this->operator();

        $this->actingAs($user)
            ->post(route('switches.rstp.storm.store', $switch), [
                'interface_name' => 'ge-0/0/9',
                'level' => 80,
                'reason' => 'yayın fırtınası şüphesi',
            ])
            ->assertSessionHasNoErrors();

        $kayit = SwitchWriteJob::query()->firstOrFail();

        $this->assertSame($user->id, $kayit->user_id);
        $this->assertSame('yayın fırtınası şüphesi', $kayit->reason);
        $this->assertNotNull($kayit->ip_address);
    }

    /**
     * Etkinlik kaydına PANELİN NİYETİ değil, CİHAZIN KABUL ETTİĞİ değişiklik
     * geçer. Fark ancak iş bitince biliniyor.
     */
    public function test_etkinlik_kaydi_cihazin_farkiyla_yazilir(): void
    {
        $switch = $this->switch();
        $this->port($switch, 'ge-0/0/9');
        $this->device();
        $user = $this->operator();

        $this->actingAs($user)
            ->post(route('switches.rstp.storm.store', $switch), [
                'interface_name' => 'ge-0/0/9',
                'level' => 80,
                'reason' => 'gerekçe',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'configured',
            'channel' => ActivityChannel::Config->value,
            'reason' => 'gerekçe',
        ]);

        $kayit = SwitchWriteJob::query()->firstOrFail();
        $this->assertSame(SwitchWriteStatus::Completed, $kayit->status);
        $this->assertNotNull($kayit->diff);
    }

    /**
     * Cihaz reddederse kullanıcı SEBEBİNİ görmeli: kuyrukta hata toast
     * olarak görünemez, kayda geçmeli.
     */
    public function test_dusen_is_sebebiyle_kaydedilir(): void
    {
        $switch = $this->switch();

        $kayit = SwitchWriteJob::create([
            'switch_id' => $switch->id,
            'summary' => 'deneme',
            'status' => SwitchWriteStatus::Queued,
        ]);

        $is = new ApplySwitchWriteJob(
            $kayit,
            $switch,
            StormControlConfigurator::class,
            'apply',
            ['ge-0/0/9', ['level' => 80]],
        );

        $is->failed(NetconfException::transport('cihaz reddetti', 'rpc'));

        $kayit->refresh();

        $this->assertSame(SwitchWriteStatus::Failed, $kayit->status);
        $this->assertSame('cihaz reddetti', $kayit->message);
        $this->assertNotNull($kayit->finished_at);
    }

    /**
     * Cihaz yazması KÖR TEKRAR EDİLMEZ: yarım uygulanmış bir değişikliğe
     * ikinci kez yazmak, panelin bilmediği bir duruma yazmaktır.
     */
    public function test_yazma_isi_tekrar_denenmez(): void
    {
        $switch = $this->switch();

        $is = new ApplySwitchWriteJob(
            new SwitchWriteJob(['switch_id' => $switch->id, 'summary' => 'x']),
            $switch,
            StormControlConfigurator::class,
            'apply',
            [],
        );

        $this->assertSame(1, $is->tries);
        $this->assertSame(config('netconf.write_queue'), $is->queue);
    }

    /**
     * Ekran durumu yoklar; uç switchi GÖRME yetkisi ister.
     */
    public function test_durum_ucu_yetki_ister(): void
    {
        $switch = $this->switch();

        $kayit = SwitchWriteJob::create([
            'switch_id' => $switch->id,
            'summary' => 'deneme',
            'status' => SwitchWriteStatus::Running,
        ]);

        $yabanci = User::factory()->create();

        $this->actingAs($yabanci)
            ->getJson(route('switches.write-jobs.show', [$switch, $kayit]))
            ->assertForbidden();

        $this->actingAs($this->operator())
            ->getJson(route('switches.write-jobs.show', [$switch, $kayit]))
            ->assertOk()
            ->assertJson([
                'status' => 'running',
                'finished' => false,
                'summary' => 'deneme',
            ]);
    }

    /**
     * BAŞKA switchin işi o switchin adresinden okunamaz.
     */
    public function test_baska_switchin_isi_okunamaz(): void
    {
        $switch = $this->switch();
        $digeri = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.196']);

        $kayit = SwitchWriteJob::create([
            'switch_id' => $digeri->id,
            'summary' => 'deneme',
            'status' => SwitchWriteStatus::Running,
        ]);

        $this->actingAs($this->operator())
            ->getJson(route('switches.write-jobs.show', [$switch, $kayit]))
            ->assertNotFound();
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice('[edit ethernet-switching-options]');
        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function port(NetworkSwitch $switch, string $name): SwitchPort
    {
        return SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => $name,
            'port_mode' => PortMode::Access,
        ]);
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
