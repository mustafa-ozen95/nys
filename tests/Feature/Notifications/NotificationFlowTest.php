<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\CredentialProtocol;
use App\Enums\PortMode;
use App\Enums\SwitchWriteStatus;
use App\Models\DeviceOperation;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\SwitchWriteJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bildirim akışı: olan biten.
 *
 * Cihazda olan bir şey, onu başlatan kişinin değil AĞIN olayıdır. Bir
 * cihazın eşitlemesi tekrar tekrar düşerse hata kimsenin bakmadığı bir
 * listede kalmamalı.
 */
final class NotificationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_tamamlanan_yazma_herkese_gorunur(): void
    {
        $switch = $this->switch();
        $other = User::factory()->create();

        SwitchWriteJob::create([
            'switch_id' => $switch->id,
            'user_id' => $other->id,
            'summary' => 'ge-0/0/5 portu yazıldı',
            'status' => SwitchWriteStatus::Completed,
            'diff' => '[edit interfaces ge-0/0/5] + speed 100m;',
            'finished_at' => now(),
        ]);

        $this->actingAs($this->operator())
            ->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonPath('items.0.title', 'ge-0/0/5 portu yazıldı')
            ->assertJsonPath('items.0.label', 'Cihaza yazıldı')
            ->assertJsonPath('items.0.actor', $other->name)
            ->assertJsonPath('unseen', 1);
    }

    /**
     * Cihazın reddettiği işlemler de akışa girer — eşitleme, ölçüm, sonda.
     * Yazma hataları iki kez görünmesin diye yazma işlemleri hariç tutulur.
     */
    public function test_cihaz_hatalari_akisa_girer(): void
    {
        $switch = $this->switch();

        DeviceOperation::create([
            'switch_id' => $switch->id,
            'target' => $switch->hostname,
            'operation' => 'sync',
            'status' => 'failed',
            'summary' => 'NETCONF eşitlemesi',
            'device_message' => 'syntax error, expecting </configuration>',
        ]);

        DeviceOperation::create([
            'switch_id' => $switch->id,
            'target' => $switch->hostname,
            'operation' => 'write',
            'status' => 'failed',
            'summary' => 'bu yazma hatası akışta TEKRARLANMAZ',
        ]);

        $response = $this->actingAs($this->operator())
            ->getJson(route('notifications.index'))
            ->assertOk();

        $headers = collect($response->json('items'))->pluck('title');

        $this->assertContains('NETCONF eşitlemesi', $headers);
        $this->assertNotContains('bu yazma hatası akışta TEKRARLANMAZ', $headers);
    }

    /**
     * Kullanıcı YALNIZCA görebildiği switchlerin olaylarını görür.
     */
    public function test_gorulemeyen_switchin_olayi_sizmaz(): void
    {
        $hidden = NetworkSwitch::factory()->create(['hostname' => 'gizli-switch']);

        SwitchWriteJob::create([
            'switch_id' => $hidden->id,
            'summary' => 'gizli işlem',
            'status' => SwitchWriteStatus::Completed,
            'finished_at' => now(),
        ]);

        // Switch görme yetkisi OLMAYAN kullanıcı hiçbir olayı görmez.
        $user = User::factory()->create();

        $headers = collect(
            $this->actingAs($user->fresh())->getJson(route('notifications.index'))->json('items'),
        )->pluck('title');

        $this->assertNotContains('gizli işlem', $headers);
    }

    /**
     * "Okundu" KİŞİYE özeldir: biri baktı diye ötekinin sayacı sıfırlanmaz.
     */
    public function test_okundu_kisiye_ozeldir(): void
    {
        $switch = $this->switch();

        SwitchWriteJob::create([
            'switch_id' => $switch->id,
            'summary' => 'bir işlem',
            'status' => SwitchWriteStatus::Completed,
            'finished_at' => now(),
        ]);

        $ali = $this->operator();
        $veli = $this->operator();

        $this->actingAs($ali)->postJson(route('notifications.seen'))->assertOk();

        $this->assertSame(0, $this->actingAs($ali->fresh())->getJson(route('notifications.index'))->json('unseen'));
        $this->assertSame(1, $this->actingAs($veli)->getJson(route('notifications.index'))->json('unseen'));
    }

    /**
     * Akış bir ARŞİV değildir; penceresi dışındaki olay görünmez.
     */
    public function test_eski_olay_akista_durmaz(): void
    {
        $switch = $this->switch();

        SwitchWriteJob::create([
            'switch_id' => $switch->id,
            'summary' => 'çok eski işlem',
            'status' => SwitchWriteStatus::Completed,
            'finished_at' => now()->subHours((int) config('activity.notifications.window_hours') + 1),
        ]);

        $this->assertSame(
            [],
            $this->actingAs($this->operator())->getJson(route('notifications.index'))->json('items'),
        );
    }

    /**
     * SÜREN iş akışta yoktur: bitmemiş bir işlem "olan biten" değildir.
     */
    public function test_suren_is_akista_gorunmez(): void
    {
        $switch = $this->switch();

        SwitchWriteJob::create([
            'switch_id' => $switch->id,
            'summary' => 'süren işlem',
            'status' => SwitchWriteStatus::Running,
        ]);

        $this->assertSame(
            [],
            $this->actingAs($this->operator())->getJson(route('notifications.index'))->json('items'),
        );
    }

    /**
     * SÜREN bir yazma varken aynı kayda ikinci yazma KABUL EDİLMEZ.
     *
     * İkincisi birincinin sonucunu görmeden hazırlanmış olur ve port
     * ekranında "gönderilmeyen alan silinir" kuralı geçerli — birincinin
     * etkisi sessizce geri alınabilirdi.
     */
    public function test_suren_islem_ayni_porta_ikinci_yazmayi_engeller(): void
    {
        $switch = $this->switch();
        $port = SwitchPort::factory()->for($switch, 'switch')->create([
            'name' => 'ge-0/0/5',
            'port_mode' => PortMode::Access,
        ]);

        SwitchWriteJob::create([
            'switch_id' => $switch->id,
            'summary' => 'ge-0/0/5 portu yazıldı',
            'status' => SwitchWriteStatus::Running,
            'subject_type' => $port->getMorphClass(),
            'subject_id' => $port->id,
        ]);

        $this->actingAs($this->operator())
            ->put(route('switches.ports.update', [$switch, $port]), [
                'port_mode' => PortMode::Access->value,
                'record_version' => $port->updated_at?->getTimestamp(),
            ])
            ->assertSessionHasErrors('write_job');
        $this->assertSame(1, SwitchWriteJob::query()->count(), 'İkinci iş oluşturuldu.');
    }

    /**
     * Cihaza YAZAN yetenekler NETCONF kimliği ister
     * (bkz. DeviceObjectAbilities::WRITE_ACTIONS); kimliksiz switchte port
     * güncelleme ucu 403 döner.
     */
    /**
     * "Okundu" AÇIK BİR EYLEMDİR ve KİŞİYE özeldir.
     *
     * Paneli açmak tek başına işaretlemez: kullanıcı bakmadan açıp
     * kapatabilir ve sayacı kaybetmek, görülmemiş bir hatayı kaybetmektir.
     */
    public function test_okundu_yalnizca_isaretleyeni_etkiler(): void
    {
        $switch = $this->switch();

        SwitchWriteJob::create([
            'switch_id' => $switch->id,
            'summary' => 'bir işlem',
            'status' => SwitchWriteStatus::Completed,
            'finished_at' => now(),
        ]);

        $ali = $this->operator();
        $veli = $this->operator();

        // Sadece BAKMAK sayacı düşürmez.
        $this->assertSame(1, $this->actingAs($ali)->getJson(route('notifications.index'))->json('unseen'));
        $this->assertSame(1, $this->actingAs($ali->fresh())->getJson(route('notifications.index'))->json('unseen'));

        $this->actingAs($ali->fresh())->postJson(route('notifications.seen'))->assertOk();

        $this->assertSame(0, $this->actingAs($ali->fresh())->getJson(route('notifications.index'))->json('unseen'));
        $this->assertSame(1, $this->actingAs($veli)->getJson(route('notifications.index'))->json('unseen'));
    }

    /**
     * Panelde hem "tümünü okundu işaretle" hem de listeye giden bağlantı
     * durur; ikisi de okundu sayar.
     */
    public function test_panelde_okundu_eylemleri_sunulur(): void
    {
        $this->actingAs($this->operator())
            ->get(route('overview'))
            ->assertOk()
            ->assertSee('Tümünü okundu işaretle')
            ->assertSee('data-notification-all', false);
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
        $user = User::factory()->admin()->create();

        return $user->fresh();
    }
}
