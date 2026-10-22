<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Models\ActivityLog;
use App\Models\NetworkSwitch;
use App\Models\SwitchSnmpCommunity;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Cihazın kendi ayarları ve SNMP community'leri.
 *
 * Bunlar cihazda duruyordu ama panel göremiyordu: görülmeyen ayar
 * denetlenemez, terminalden değiştirildiğinde kimse fark etmez.
 */
final class DeviceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cihaz_ayarlari_tek_committe_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.device-settings', $switch), [
                'hostname' => 'jn32-yeni',
                'time_zone' => 'Europe/Istanbul',
                'name_servers' => "8.8.8.8\n1.1.1.1",
                'ntp_servers' => '192.0.2.51',
                'syslog_hosts' => '192.0.2.51',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<host-name>jn32-yeni</host-name>', $written);
        $this->assertStringContainsString('<time-zone>Europe/Istanbul</time-zone>', $written);
        $this->assertStringContainsString('<name-server><name>8.8.8.8</name></name-server>', $written);
        $this->assertStringContainsString('<ntp><server><name>192.0.2.51</name></server></ntp>', $written);
        // Günlük hedefi ne alacağını da söylemek zorundadır.
        $this->assertStringContainsString('<contents><name>any</name><notice/></contents>', $written);

        // Cihazın adı değişince envanterinki de değişir; ikisi ayrışmamalı.
        $this->assertSame('jn32-yeni', $switch->fresh()->hostname);
    }

    /**
     * Listeden çıkarılan sunucu cihazdan SİLİNMELİ: merge yalnızca ekler,
     * yalnızca yeni listeyi yazmak eskisini bırakırdı.
     */
    public function test_listeden_cikarilan_dns_cihazdan_silinir(): void
    {
        $switch = $this->switch();
        $switch->update(['name_servers' => ['8.8.8.8', '1.1.1.1']]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.device-settings', $switch), [
                'hostname' => $switch->hostname,
                'name_servers' => '8.8.8.8',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<name-server delete="delete"><name>1.1.1.1</name></name-server>',
            $device->payload(),
        );
    }

    public function test_snmp_konum_ve_iletisim_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.snmp-identity', $switch), [
                'name' => 'test1',
                'location' => 'Lokasyon-A',
                'contact' => 'teknik@ornek.test',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<location>Lokasyon-A</location>', $written);
        $this->assertStringContainsString('<contact>teknik@ornek.test</contact>', $written);
    }

    public function test_bos_konum_cihazdan_silinir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.snmp-identity', $switch), ['name' => 'test1'])
            ->assertRedirect();

        $this->assertStringContainsString('<location delete="delete"/>', $device->payload());
    }

    public function test_snmp_community_eklenir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.snmp-communities.store', $switch), [
                'name' => 'gizlicommunity',
                'authorization' => 'read-only',
                'clients' => '198.18.20.0/24',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<community><name>gizlicommunity</name>', $written);
        $this->assertStringContainsString('<authorization>read-only</authorization>', $written);
        $this->assertStringContainsString('<clients><name>198.18.20.0/24</name></clients>', $written);
    }

    /**
     * Community adı okuma parolası gibi çalışır: kayda düz yazılmamalı.
     */
    public function test_community_adi_etkinlik_kaydina_dusmez(): void
    {
        $switch = $this->switch();
        $this->device(diff: '[edit snmp] + community "gizlicommunity" { authorization read-only; }');

        $this->actingAs($this->operator())
            ->post(route('switches.snmp-communities.store', $switch), [
                'name' => 'gizlicommunity',
                'authorization' => 'read-only',
            ])
            ->assertRedirect();

        $this->assertSame(
            0,
            ActivityLog::query()->where('description', 'like', '%gizlicommunity%')->count(),
        );
    }

    /**
     * İstemci listesi değişince eskisi kalmamalı; düğüm komple değiştirilir.
     *
     * Adsız "<clients delete>" cihazda (12.3R12.4) SÖZDİZİMİ HATASI veriyor
     * ve bütün yükleme düşüyor — archive-sites ile aynı tuzak.
     */
    public function test_community_guncellemesi_replace_kullanir(): void
    {
        $switch = $this->switch();
        $community = SwitchSnmpCommunity::factory()->for($switch, 'switch')
            ->create(['name' => 'mevcut', 'clients' => ['10.0.0.0/8']]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.snmp-communities.update', [$switch, $community]), [
                '_duzenleme' => '1',
                'authorization' => 'read-only',
                'clients' => '198.18.20.0/24',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<community replace="replace">', $written);
        $this->assertStringNotContainsString('<clients delete="delete"/>', $written);
    }

    /**
     * Şasi alarmları "chassis" altındadır ama aynı commit'te gider: cihaz
     * bir an yarım ayarla kalmamalı.
     */
    public function test_sasi_alarmlari_ayni_committe_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.device-settings', $switch), [
                'hostname' => $switch->hostname,
                'alarm_ethernet_link_down' => 'red',
                'alarm_management_link_down' => 'yellow',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<chassis><alarm>', $written);
        $this->assertStringContainsString('<ethernet><link-down>red</link-down></ethernet>', $written);
        $this->assertStringContainsString(
            '<management-ethernet><link-down>yellow</link-down></management-ethernet>',
            $written,
        );
        $this->assertStringContainsString('<host-name>'.$switch->hostname.'</host-name>', $written);
    }

    public function test_bos_alarm_cihazdan_silinir(): void
    {
        $switch = $this->switch();
        $switch->update(['alarm_ethernet_link_down' => 'red']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.device-settings', $switch), [
                'hostname' => $switch->hostname,
                'alarm_ethernet_link_down' => '',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<ethernet><link-down delete="delete"/></ethernet>',
            $device->payload(),
        );
    }

    /**
     * Cihaz tanımadığı seviyeyi "invalid value" ile reddediyor; panel onu
     * cihaza hiç götürmemeli.
     */
    public function test_tanimsiz_alarm_seviyesi_kabul_edilmez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.device-settings', $switch), [
                'hostname' => $switch->hostname,
                'alarm_ethernet_link_down' => 'mor',
            ])
            ->assertSessionHasErrors('alarm_ethernet_link_down');

        $this->assertNull($device->connection);
    }

    public function test_cihaza_yazamayan_ayar_degistiremez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        $this->actingAs($user->fresh())
            ->put(route('switches.device-settings', $switch), ['hostname' => 'baska'])
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    private function device(?string $diff = null): FakeJunosDevice
    {
        $device = $diff === null ? new FakeJunosDevice : new FakeJunosDevice(diff: $diff);

        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'update', 'configure']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'test1', 'management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
