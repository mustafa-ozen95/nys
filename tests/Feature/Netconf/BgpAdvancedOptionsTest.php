<?php

declare(strict_types=1);

namespace Tests\Feature\Netconf;

use App\Enums\CredentialProtocol;
use App\Models\ActivityLog;
use App\Models\BgpGroup;
use App\Models\BgpNeighbor;
use App\Models\NetworkSwitch;
use App\Models\SwitchPolicyStatement;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Junos'un sunduğu ileri BGP ayarları panelden yazılabilir.
 *
 * Eksik oldukları sürece bu ayarlar terminalden kuruluyordu ve panel onları
 * göremediği için bir sonraki güncellemede SESSİZCE siliyordu: güncelleme
 * "önce yönetilen alanları sil, sonra yaz" biçimindedir ve yalnızca panelin
 * bildiği alanları geri yazar. Bu yüzden her alan hem yazılır hem okunur.
 */
final class BgpAdvancedOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_grup_ileri_ayarlariyla_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'transit',
                'type' => 'external',
                'peer_asn' => '64497',
                'hold_time' => '30',
                'cluster' => '10.0.0.1',
                'graceful_restart' => 'enable',
                'remove_private' => '1',
                'as_override' => '1',
                'advertise_inactive' => '1',
                'multipath' => '1',
                'damping' => '1',
                'out_delay' => '15',
                'bfd_minimum_interval' => '300',
                'allow_prefixes' => "10.9.0.0/24\n10.9.1.0/24",
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = $device->payload();

        $this->assertStringContainsString('<hold-time>30</hold-time>', $yazilan);
        $this->assertStringContainsString('<cluster>10.0.0.1</cluster>', $yazilan);
        $this->assertStringContainsString('<graceful-restart/>', $yazilan);
        $this->assertStringContainsString('<remove-private/>', $yazilan);
        $this->assertStringContainsString('<as-override/>', $yazilan);
        $this->assertStringContainsString('<advertise-inactive/>', $yazilan);
        $this->assertStringContainsString('<multipath/>', $yazilan);
        $this->assertStringContainsString('<damping/>', $yazilan);
        $this->assertStringContainsString('<out-delay>15</out-delay>', $yazilan);
        $this->assertStringContainsString(
            '<bfd-liveness-detection><minimum-interval>300</minimum-interval></bfd-liveness-detection>',
            $yazilan,
        );
        $this->assertStringContainsString('<allow>10.9.0.0/24</allow>', $yazilan);
        $this->assertStringContainsString('<allow>10.9.1.0/24</allow>', $yazilan);
    }

    /**
     * "Cihazın genel ayarı" ile "kapalı" aynı şey değildir: ilki satır
     * yazmaz, ikincisi açıkça kapatır.
     */
    /**
     * Dinamik komşu ile MD5 anahtarı BİRLİKTE olmaz.
     *
     * Cihaz reddediyor: "May not be configured with authentication-key"
     * (jn32-test, 12.3R12.4). Kural panelde de durmalı, yoksa kullanıcı
     * formu kaydeder ve neden reddedildiğini anlayamaz.
     */
    public function test_dinamik_komsu_ile_anahtar_birlikte_verilemez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'dinamik', 'type' => 'external', 'peer_asn' => '65001',
                'authentication_key' => 'anahtar',
                'allow_prefixes' => '10.9.0.0/24',
            ])
            ->assertSessionHasErrors('allow_prefixes');

        $this->assertNull($device->connection);
    }

    public function test_anahtar_dinamik_komsu_olmadan_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'transit', 'type' => 'external', 'peer_asn' => '65001',
                'authentication_key' => 'cok-gizli-anahtar',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertStringContainsString(
            '<authentication-key>cok-gizli-anahtar</authentication-key>',
            $device->payload(),
        );
    }

    /**
     * "Cihazın genel ayarı" satır YAZMAZ.
     *
     * Üç durum ayrı testlerdedir: NetconfClient taşıyıcıyı bir kez çözüp
     * saklıyor, tek test içinde ikinci bir sahte cihaz bağlansa da yazma
     * ilkine gidiyor.
     */
    public function test_graceful_restart_varsayilanda_satir_yazilmaz(): void
    {
        $this->assertStringNotContainsString(
            'graceful-restart',
            $this->groupPayload('varsayilan', 'default'),
        );
    }

    public function test_graceful_restart_acikca_acilir(): void
    {
        $this->assertStringContainsString('<graceful-restart/>', $this->groupPayload('acik', 'enable'));
    }

    /**
     * Kapatmak ile hiç yazmamak aynı şey değildir: genel ayarı açık olan
     * cihazda grup sessizce açık kalırdı.
     */
    public function test_graceful_restart_acikca_kapatilir(): void
    {
        $this->assertStringContainsString(
            '<graceful-restart><disable/></graceful-restart>',
            $this->groupPayload('kapali', 'disable'),
        );
    }

    private function groupPayload(string $name, string $gracefulRestart): string
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => $name, 'type' => 'external', 'peer_asn' => '65001',
                'graceful_restart' => $gracefulRestart,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return $device->payload();
    }

    /**
     * Anahtar parola gibi davranır: boş bırakmak "dokunma" demektir.
     * Cihazdan okunan "$9$..." biçimi geri yazılır; Junos kendi şifrelediği
     * değeri kabul eder.
     */
    public function test_bos_birakilan_anahtar_korunur(): void
    {
        $switch = $this->switch();
        $group = BgpGroup::factory()->for($switch, 'switch')->create([
            'name' => 'transit',
            'authentication_key' => '$9$cihazdan-okunan',
        ]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.bgp.groups.update', [$switch, $group]), [
                'name' => 'transit', 'type' => 'external', 'peer_asn' => '65001',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<authentication-key>$9$cihazdan-okunan</authentication-key>',
            $device->payload(),
        );
    }

    public function test_anahtar_acikca_silinebilir(): void
    {
        $switch = $this->switch();
        $group = BgpGroup::factory()->for($switch, 'switch')->create([
            'name' => 'transit',
            'authentication_key' => '$9$cihazdan-okunan',
        ]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.bgp.groups.update', [$switch, $group]), [
                'name' => 'transit', 'type' => 'external', 'peer_asn' => '65001',
                'clear_authentication_key' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = $device->payload();

        // Silme belgesi durur, yeniden yazma olmaz.
        $this->assertStringContainsString('<authentication-key delete="delete"/>', $yazilan);
        $this->assertStringNotContainsString('<authentication-key>$9$', $yazilan);
    }

    public function test_anahtar_ile_birlikte_yeni_deger_verilemez(): void
    {
        $switch = $this->switch();
        $group = BgpGroup::factory()->for($switch, 'switch')->create(['name' => 'transit']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.bgp.groups.update', [$switch, $group]), [
                'name' => 'transit', 'type' => 'external', 'peer_asn' => '65001',
                'authentication_key' => 'yeni', 'clear_authentication_key' => '1',
            ])
            ->assertSessionHasErrors('authentication_key');

        $this->assertNull($device->connection);
    }

    /**
     * Cihaz farkı etkinlik kaydına yazılır; kayıt silinemez ve dışa
     * aktarılabilir olduğu için sır oraya girmemeli.
     */
    public function test_oturum_anahtari_etkinlik_kaydina_dusmez(): void
    {
        $switch = $this->switch();
        $this->device(diff: '[edit protocols bgp group transit]'."\n".'+   authentication-key "$9$gizliDeger"; ## SECRET-DATA');

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'transit', 'type' => 'external', 'peer_asn' => '65001',
                'authentication_key' => 'duz-metin-anahtar',
            ])
            ->assertRedirect();

        $kayitlar = ActivityLog::query()->pluck('description')->implode(' ');

        $this->assertStringNotContainsString('$9$gizliDeger', $kayitlar);
        $this->assertStringNotContainsString('duz-metin-anahtar', $kayitlar);
        $this->assertStringContainsString('authentication-key', $kayitlar);
    }

    public function test_hizasiz_dinamik_komsu_agi_reddedilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'dinamik', 'type' => 'external', 'peer_asn' => '65001',
                'allow_prefixes' => '10.9.0.5/24',
            ])
            ->assertSessionHasErrors('allow_prefixes.0');

        $this->assertNull($device->connection);
    }

    public function test_komsu_grup_ayarlarini_ezer(): void
    {
        $switch = $this->switch();
        $group = BgpGroup::factory()->for($switch, 'switch')->create(['name' => 'transit']);
        SwitchPolicyStatement::factory()->for($switch, 'switch')->create(['name' => 'gelen']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.neighbors.store', [$switch, $group]), [
                'address' => '198.51.100.134',
                'authentication_key' => 'komsuya-ozel',
                'hold_time' => '45',
                'passive' => '1',
                'multihop' => '1',
                'multihop_ttl' => '2',
                'import_policies' => ['gelen'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = $device->payload();

        $this->assertStringContainsString('<neighbor><name>198.51.100.134</name>', $yazilan);
        $this->assertStringContainsString('<authentication-key>komsuya-ozel</authentication-key>', $yazilan);
        $this->assertStringContainsString('<hold-time>45</hold-time>', $yazilan);
        $this->assertStringContainsString('<passive/>', $yazilan);
        $this->assertStringContainsString('<multihop><ttl>2</ttl></multihop>', $yazilan);
        $this->assertStringContainsString('<import>gelen</import>', $yazilan);
    }

    /**
     * Komşu güncellemesi de "önce sil, sonra yaz" akışıdır; silinecek alan
     * listesi eksik kalırsa panelden boşaltılamayan bir alan olur.
     */
    public function test_komsu_guncellemesi_yonetilen_alanlari_once_siler(): void
    {
        $switch = $this->switch();
        $group = BgpGroup::factory()->for($switch, 'switch')->create(['name' => 'transit']);
        $neighbor = BgpNeighbor::factory()->for($group, 'group')->for($switch, 'switch')
            ->create(['address' => '198.51.100.134']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.bgp.neighbors.update', [$switch, $group, $neighbor]), [
                'address' => '198.51.100.134',
            ])
            ->assertRedirect();

        $yazilan = $device->payload();

        foreach (['authentication-key', 'hold-time', 'passive', 'multihop', 'import', 'export'] as $alan) {
            $this->assertStringContainsString("<{$alan} delete=\"delete\"/>", $yazilan);
        }
    }

    /**
     * Adres cihazda komşunun kimliğidir; güncelleme belgesi ESKİ adresi
     * taşır. Değiştirilmiş adres sessizce yok sayılmamalı, reddedilmeli.
     */
    public function test_komsu_adresi_duzenlemede_degistirilemez(): void
    {
        $switch = $this->switch();
        $group = BgpGroup::factory()->for($switch, 'switch')->create(['name' => 'transit']);
        $neighbor = BgpNeighbor::factory()->for($group, 'group')->for($switch, 'switch')
            ->create(['address' => '198.51.100.134']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.bgp.neighbors.update', [$switch, $group, $neighbor]), [
                'address' => '198.51.100.135',
            ])
            ->assertSessionHasErrors('address');

        $this->assertNull($device->connection);
    }

    /**
     * Junos aralığı süreksizdir: 0 geçerli, 1 ve 2 değil, 3'ten sonrası
     * serbest. Tek bir "min" kuralı bunu ifade edemez.
     */
    public function test_hold_time_sifir_kabul_edilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'transit', 'type' => 'external', 'peer_asn' => '65001', 'hold_time' => '0',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // Sıfır "satır yazma" demek değildir; cihazda açıkça 0 durur.
        $this->assertStringContainsString('<hold-time>0</hold-time>', $device->payload());
    }

    public function test_hold_time_bir_ve_iki_reddedilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.bgp.groups.store', $switch), [
                'name' => 'transit', 'type' => 'external', 'peer_asn' => '65001', 'hold_time' => '2',
            ])
            ->assertSessionHasErrors('hold_time');

        $this->assertNull($device->connection);
    }

    private function switch(string $ip = '198.18.20.195'): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create([
            'management_ip' => $ip,
            'autonomous_system' => '65000',
        ]);

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

    private function device(?string $diff = null): FakeJunosDevice
    {
        $device = $diff === null ? new FakeJunosDevice : new FakeJunosDevice(diff: $diff);

        $this->app->instance(NetconfTransport::class, $device);

        return $device;
    }
}
