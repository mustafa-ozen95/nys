<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\JunosUserClass;
use App\Models\NetworkSwitch;
use App\Models\SwitchLoginUser;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Switchin kendi ayarları tek ekranda toplanır.
 *
 * SNMP adı ve yapılandırma yedeği genel bakış panelinin altındaki düğmelere
 * dağılmaz; yeni bir cihaz ayarının eklenecek yeri bellidir.
 */
final class SwitchSettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_ekran_cihaz_ayarlarini_toplar(): void
    {
        $switch = $this->switch();

        $this->actingAs($this->operator())
            ->get(route('switches.settings', $switch))
            ->assertOk()
            ->assertSee('SNMP adı')
            ->assertSee('Yapılandırma yedeği');
    }

    public function test_ekran_cihaz_hesaplarini_gosterir(): void
    {
        $switch = $this->switch();
        SwitchLoginUser::factory()->for($switch, 'switch')->create([
            'name' => 'saha',
            'class' => JunosUserClass::ReadOnly->value,
        ]);
        SwitchLoginUser::factory()->for($switch, 'switch')->panel()->create();

        $this->actingAs($this->operator())
            ->get(route('switches.settings', $switch))
            ->assertOk()
            ->assertSee('Cihaz hesapları')
            ->assertSee('saha')
            // Panelin bağlandığı hesap işaretlenir; silme formu hiç
            // basılmaz. (Silme ve düzenleme aynı adrestedir, ayıran yöntem
            // olduğu için adres değil formun kendisi aranır.)
            ->assertSee('panel')
            ->assertDontSee('hesap-sil-'.$switch->loginUsers->firstWhere('name', 'admin')->uuid);
    }

    public function test_ekran_sasi_alarmlarini_gosterir(): void
    {
        $switch = $this->switch();
        $switch->update(['alarm_ethernet_link_down' => 'red']);

        $this->actingAs($this->operator())
            ->get(route('switches.settings', $switch))
            ->assertOk()
            ->assertSee('Port düşmesi alarmı')
            ->assertSee('Kırmızı (kritik)');
    }

    public function test_cihaza_yazamayan_ekrani_goremez(): void
    {
        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);

        $this->actingAs($user->fresh())
            ->get(route('switches.settings', $switch))
            ->assertForbidden();
    }

    public function test_menude_yalnizca_yazabilene_gorunur(): void
    {
        $switch = $this->switch();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        /*
         * Görsel önlem: yazamayan kullanıcı bağlantıyı hiç görmemeli.
         * Sunucu tarafı zaten reddediyor; ikisi birlikte durur.
         */
        $this->actingAs($user->fresh())
            ->get(route('switches.show', $switch))
            ->assertOk()
            ->assertDontSee(route('switches.settings', $switch));
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        $this->grantDeviceView($user);

        return $user->fresh();
    }

    private function switch(): NetworkSwitch
    {
        $switch = NetworkSwitch::factory()->create(['hostname' => 'jn32-test', 'management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        return $switch->refresh();
    }
}
