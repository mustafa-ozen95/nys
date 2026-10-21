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
 * BGP yazma ayrı bir yetkidir.
 *
 * Port ya da VLAN değiştirebilen biri, komşuluk kurup bir bloğu internete
 * anons edebilecek biri değildir; etkisi cihazın dışına, komşu ağa taşan tek
 * yazma yolu budur.
 *
 * Yetki switch bazında verilir: bir kullanıcıya yalnızca belirli switchlerde
 * tanınabilir ya da yalnızca belirli switchlerde reddedilebilir.
 */
final class BgpPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_diger_nesne_yetkileri_bgpyi_kapsamaz(): void
    {
        $switch = $this->switch();

        // Firewall dışındaki her şeye yazabilen ama BGP'ye yazamayan kullanıcı.
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceWrite($user);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_bgp', ['view']);

        $this->actingAs($user->fresh())
            ->post(route('switches.bgp.groups.store', $switch), [])
            ->assertForbidden();
    }

    public function test_bgp_yetkisi_olan_ekrani_kullanabilir(): void
    {
        $switch = $this->switch();
        $user = $this->userWithBgp(['view', 'create', 'update', 'delete']);

        // Yetki geçer; doğrulama hatası alınması yetkinin geçtiğini gösterir.
        $this->actingAs($user)
            ->post(route('switches.bgp.groups.store', $switch), [])
            ->assertSessionHasErrors();
    }

    public function test_bgp_yetkisi_switch_bazinda_reddedilebilir(): void
    {
        $izinli = $this->switch('izinli', '198.18.20.195');
        $yasakli = $this->switch('yasakli', '198.18.20.196');

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        app(UserPermissionResolver::class)->syncResource(
            $user,
            'switch_bgp',
            ['view', 'create', 'update', 'delete'],
            deniedRecords: ['create' => [$yasakli->id]],
        );

        $user = $user->fresh();

        $this->actingAs($user)
            ->post(route('switches.bgp.groups.store', $yasakli), [])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('switches.bgp.groups.store', $izinli), [])
            ->assertSessionHasErrors();
    }

    public function test_netconf_kimligi_yoksa_yetki_ise_yaramaz(): void
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'kimliksiz']);
        $user = $this->userWithBgp(['view', 'create', 'update', 'delete']);

        $this->actingAs($user)
            ->post(route('switches.bgp.groups.store', $switch), [])
            ->assertForbidden();
    }

    /**
     * BGP kaynağında verilen yetkilere sahip kullanıcı.
     *
     * Switch yalnızca GÖRÜLÜR; BGP yazma yetkisi ayrı kaynaktan gelir.
     *
     * @param  list<string>  $actions
     */
    private function userWithBgp(array $actions): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_bgp', $actions);

        return $user->fresh();
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
