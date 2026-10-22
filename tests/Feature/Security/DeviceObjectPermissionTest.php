<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\CredentialProtocol;
use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cihaz nesnelerinin ayrı yetkileri.
 *
 * "Switchi okur-yazar" diye tek bir anahtar yoktur: port, VLAN, route,
 * firewall, politika, toplama ve BGP ayrı kaynaklardır ve her biri kendi
 * görüntüle/oluştur/düzenle/sil yetkisini taşır. Yetki switch bazında
 * verilir, yani "A switchinin portlarına dokunsun, B switchine hiç
 * girmesin" denebilir.
 */
final class DeviceObjectPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Bu testlerin konusu YETKİ; ölçüm kaynağına gitmeleri gerekmez.
         * İzleme sistemi kapatılınca port ekranı dış istek yapmaz.
         */
        config(['zabbix.enabled' => false, 'measurement.fallback' => false]);
    }

    public function test_port_yetkisi_vlana_gecmez(): void
    {
        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_ports', ['view', 'update']);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_vlans', ['view']);
        $user = $user->fresh();

        // Portları görebilir ve düzenleyebilir.
        $this->actingAs($user)->get(route('switches.ports', $switch))->assertOk();

        // VLAN'ları görür ama YAZAMAZ.
        $this->actingAs($user)->get(route('switches.vlans', $switch))->assertOk();
        $this->actingAs($user)
            ->post(route('switches.vlans.store', $switch), ['name' => 'v10', 'vlan_tag' => 10])
            ->assertForbidden();
    }

    public function test_goremedigi_nesne_ekrani_acilmaz(): void
    {
        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_ports', ['view']);
        $user = $user->fresh();

        $this->actingAs($user)->get(route('switches.ports', $switch))->assertOk();

        // Firewall yetkisi hiç verilmedi: ekran açılmamalı.
        $this->actingAs($user)->get(route('switches.firewall', $switch))->assertForbidden();
    }

    public function test_goremedigi_nesne_menude_gorunmez(): void
    {
        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_ports', ['view']);
        $user = $user->fresh();

        /*
         * Görsel önlem ile sunucu önlemi birlikte durur: yetkisi olmayan
         * kullanıcı bağlantıyı görmemeli, görse de 403 almalı.
         */
        $this->actingAs($user)
            ->get(route('switches.ports', $switch))
            ->assertOk()
            ->assertSee('Portlar')
            ->assertDontSee('Firewall');
    }

    public function test_yetki_switch_bazinda_verilebilir(): void
    {
        $permitted = $this->switch('izinli', '198.18.20.195');
        $forbidden = $this->switch('yasakli', '198.18.20.196');

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        app(UserPermissionResolver::class)->syncResource(
            $user,
            'switch_vlans',
            ['view'],
            allowedRecords: ['create' => [$permitted->id]],
        );
        $user = $user->fresh();

        $this->actingAs($user)
            ->post(route('switches.vlans.store', $forbidden), [])
            ->assertForbidden();

        // İzinli switchte yetki geçer; doğrulama hatası bunu gösterir.
        $this->actingAs($user)
            ->post(route('switches.vlans.store', $permitted), [])
            ->assertSessionHasErrors();
    }

    public function test_network_rolu_genel_yetki_verir(): void
    {
        $switch = $this->switch();
        /* Rol canlı okunuyor: uygulanacak bir şablon yok. */
        $user = User::factory()->network()->create();

        $this->actingAs($user)->get(route('switches.vlans', $switch))->assertOk();
        $this->actingAs($user)->get(route('switches.firewall', $switch))->assertOk();
        $this->actingAs($user)->post(route('switches.vlans.store', $switch), [])->assertSessionHasErrors();
    }

    public function test_teknik_rolu_yazmayi_porta_sinirlar(): void
    {
        $switch = $this->switch();
        $user = User::factory()->role('teknik')->create();

        // Görür.
        $this->actingAs($user)->get(route('switches.vlans', $switch))->assertOk();
        $this->actingAs($user)->get(route('switches.bgp', $switch))->assertOk();

        // Porta müdahale eder, VLAN'a etmez.
        $this->actingAs($user)->get(route('switches.ports', $switch))->assertOk();
        $this->actingAs($user)->post(route('switches.vlans.store', $switch), [])->assertForbidden();
        $this->actingAs($user)->post(route('switches.bgp.groups.store', $switch), [])->assertForbidden();
    }

    private function switch(string $hostname = 'jn32-test', string $ip = '198.18.20.195'): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => $hostname, 'management_ip' => $ip]);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
