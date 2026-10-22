<?php

declare(strict_types=1);

namespace Tests\Feature\Switches;

use App\Enums\CredentialProtocol;
use App\Enums\JunosUserClass;
use App\Models\ActivityLog;
use App\Models\NetworkSwitch;
use App\Models\SwitchLoginUser;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Cihaz üzerindeki yerel giriş hesapları.
 *
 * Bu hesaplar panel kullanıcılarından ayrıdır: panelden çıkarılan biri
 * cihaza konsoldan girmeye devam edebilir. Ekran o boşluğu kapatır.
 *
 * En kritik davranış panelin bağlandığı hesabın korunmasıdır: cihaz onun
 * silinmesini de yetkisinin düşürülmesini de kabul ediyor (jn32-test ile
 * doğrulandı) ve panel bir daha bağlanamıyor.
 */
final class LoginUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_hesap_cihaza_yazilir(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.login-users.store', $switch), [
                'name' => 'saha',
                'class' => JunosUserClass::ReadOnly->value,
                'full_name' => 'Saha Ekibi',
                'uid' => 2500,
                'password' => 'Denemeler123!',
                'password_confirmation' => 'Denemeler123!',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $written = $device->payload();

        $this->assertStringContainsString('<login><user><name>saha</name>', $written);
        $this->assertStringContainsString('<class>read-only</class>', $written);
        $this->assertStringContainsString('<full-name>Saha Ekibi</full-name>', $written);
        $this->assertStringContainsString('<uid>2500</uid>', $written);
        $this->assertStringContainsString(
            '<plain-text-password-value>Denemeler123!</plain-text-password-value>',
            $written,
        );
    }

    /**
     * Parola cihazda saklanır; panelde yalnızca "var mı" bilgisi tutulur.
     */
    public function test_parola_panelde_saklanmaz(): void
    {
        $switch = $this->switch();
        $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.login-users.store', $switch), [
                'name' => 'saha',
                'class' => JunosUserClass::ReadOnly->value,
                'password' => 'Denemeler123!',
                'password_confirmation' => 'Denemeler123!',
            ])
            ->assertRedirect();

        $this->assertNotContains('password', array_keys(SwitchLoginUser::make()->getAttributes()));
        $this->assertSame(
            0,
            ActivityLog::query()->where('description', 'like', '%Denemeler123%')->count(),
        );
    }

    public function test_parola_bos_birakilinca_cihazdaki_korunur(): void
    {
        $switch = $this->switch();
        $account = SwitchLoginUser::factory()->for($switch, 'switch')->create(['name' => 'saha']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.login-users.update', [$switch, $account]), [
                '_duzenleme' => '1',
                'class' => JunosUserClass::Operator->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('authentication', $device->payload());
    }

    /**
     * Boş tam ad cihazdan SİLİNMELİ: merge yalnızca ekler, alanı boş
     * göndermek eskisini bırakırdı.
     */
    public function test_bos_tam_ad_cihazdan_silinir(): void
    {
        $switch = $this->switch();
        $account = SwitchLoginUser::factory()->for($switch, 'switch')->create(['name' => 'saha']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.login-users.update', [$switch, $account]), [
                '_duzenleme' => '1',
                'class' => JunosUserClass::ReadOnly->value,
            ])
            ->assertRedirect();

        $this->assertStringContainsString('<full-name delete="delete"/>', $device->payload());
    }

    public function test_hesap_silinir(): void
    {
        $switch = $this->switch();
        $account = SwitchLoginUser::factory()->for($switch, 'switch')->create(['name' => 'saha']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->delete(route('switches.login-users.destroy', [$switch, $account]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            '<user delete="delete"><name>saha</name></user>',
            $device->payload(),
        );
    }

    /**
     * Panelin bağlandığı hesap silinemez. Cihaz silmeyi kabul eder ve
     * panel bir daha bağlanamaz; istek cihaza hiç gitmemeli.
     */
    public function test_panel_hesabi_silinemez(): void
    {
        $switch = $this->switch();
        $account = SwitchLoginUser::factory()->for($switch, 'switch')->panel()->create();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->delete(route('switches.login-users.destroy', [$switch, $account]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($device->connection);
        $this->assertDatabaseHas('switch_login_users', ['id' => $account->id]);
    }

    public function test_panel_hesabinin_yetkisi_dusurulemez(): void
    {
        $switch = $this->switch();
        $account = SwitchLoginUser::factory()->for($switch, 'switch')->panel()->create();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.login-users.update', [$switch, $account]), [
                '_duzenleme' => '1',
                'class' => JunosUserClass::ReadOnly->value,
            ])
            ->assertSessionHasErrors('class');

        $this->assertNull($device->connection);
    }

    /**
     * Panel hesabının PAROLASI bu ekrandan değiştirilemez.
     *
     * Değiştirilseydi cihazdaki parola değişir, paneldeki kayıt eski kalır ve
     * switch bir daha yönetilemezdi — kurtarma yalnızca konsoldan olurdu.
     * Aynı cihaz satırına iki ekrandan gidiliyor; parola turu bunu biliyor
     * (kurtarma hesabı açar, parolayı panele de kaydeder), bu ekran
     * bilmiyordu.
     */
    public function test_panel_hesabinin_parolasi_bu_ekrandan_degistirilemez(): void
    {
        $switch = $this->switch();
        $account = SwitchLoginUser::factory()->for($switch, 'switch')->panel()->create();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.login-users.update', [$switch, $account]), [
                '_duzenleme' => '1',
                'class' => JunosUserClass::SuperUser->value,
                'password' => 'YeniParola1',
                'password_confirmation' => 'YeniParola1',
            ])
            ->assertSessionHasErrors('password');

        $this->assertNull($device->connection);
    }

    /**
     * Başka bir hesabın parolası serbestçe değişebilir; koruma yalnızca
     * panelin bağlandığı hesap içindir.
     */
    public function test_diger_hesabin_parolasi_degistirilebilir(): void
    {
        $switch = $this->switch();
        $account = SwitchLoginUser::factory()->for($switch, 'switch')->create(['name' => 'teknisyen']);
        $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.login-users.update', [$switch, $account]), [
                '_duzenleme' => '1',
                'class' => JunosUserClass::ReadOnly->value,
                'password' => 'YeniParola1',
                'password_confirmation' => 'YeniParola1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    /**
     * Cihaz TEK ÇEŞİT karakterden oluşan parolayı reddediyor; panel de öyle.
     *
     * Ölçüldü (test1, commit check): "12345678", "abcdefgh" ve "ABCDEFGH"
     * üçü de "require change of case, digits or punctuation" ile düştü.
     * Panelin eski deseni "rakam ya da sembol var" demeye yetiyordu, yani
     * "12345678" panelden geçip cihazda ham bir hatayla düşüyordu.
     */
    public function test_tek_sinifli_parola_panelde_durdurulur(): void
    {
        $switch = $this->switch();
        $account = SwitchLoginUser::factory()->for($switch, 'switch')->create(['name' => 'teknisyen']);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.login-users.update', [$switch, $account]), [
                '_duzenleme' => '1',
                'class' => JunosUserClass::ReadOnly->value,
                'password' => '12345678',
                'password_confirmation' => '12345678',
            ])
            ->assertSessionHasErrors('password');

        $this->assertNull($device->connection);
    }

    public function test_panel_hesabi_super_kullanici_kalabilir(): void
    {
        $switch = $this->switch();
        $account = SwitchLoginUser::factory()->for($switch, 'switch')->panel()->create();
        $this->device();

        $this->actingAs($this->operator())
            ->put(route('switches.login-users.update', [$switch, $account]), [
                '_duzenleme' => '1',
                'class' => JunosUserClass::SuperUser->value,
                'full_name' => 'Panel Hesabi',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /**
     * Cihaz aynı uid'i iki hesaba vermeye izin veriyor; iki hesap dosya
     * sisteminde tek kimlik olur ve izleri ayrılamaz.
     */
    public function test_ayni_uid_ikinci_hesaba_verilemez(): void
    {
        $switch = $this->switch();
        SwitchLoginUser::factory()->for($switch, 'switch')->create(['uid' => 2500]);
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.login-users.store', $switch), [
                'name' => 'saha',
                'class' => JunosUserClass::ReadOnly->value,
                'uid' => 2500,
            ])
            ->assertSessionHasErrors('uid');

        $this->assertNull($device->connection);
    }

    public function test_ayni_ad_ikinci_kez_eklenemez(): void
    {
        $switch = $this->switch();
        SwitchLoginUser::factory()->for($switch, 'switch')->create(['name' => 'saha']);

        $this->actingAs($this->operator())
            ->post(route('switches.login-users.store', $switch), [
                'name' => 'SAHA',
                'class' => JunosUserClass::ReadOnly->value,
            ])
            ->assertSessionHasErrors('name');
    }

    /**
     * Cihazın kendi sınırları: uid 100..64000, parola en az altı karakter
     * ve yalnızca küçük harften oluşamaz.
     */
    public function test_cihazin_reddedecegi_degerler_panelde_durur(): void
    {
        $switch = $this->switch();
        $device = $this->device();
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('switches.login-users.store', $switch), [
                'name' => 'saha',
                'class' => JunosUserClass::ReadOnly->value,
                'uid' => 99,
            ])
            ->assertSessionHasErrors('uid');

        $this->actingAs($operator)
            ->post(route('switches.login-users.store', $switch), [
                'name' => 'saha',
                'class' => JunosUserClass::ReadOnly->value,
                'password' => 'kisa1',
                'password_confirmation' => 'kisa1',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($operator)
            ->post(route('switches.login-users.store', $switch), [
                'name' => 'saha',
                'class' => JunosUserClass::ReadOnly->value,
                'password' => 'yalnizkucuk',
                'password_confirmation' => 'yalnizkucuk',
            ])
            ->assertSessionHasErrors('password');

        $this->assertNull($device->connection);
    }

    /**
     * Cihaz tanımsız sınıfı reddediyor: "Undefined class 'yonetici'".
     */
    public function test_tanimsiz_sinif_kabul_edilmez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $this->actingAs($this->operator())
            ->post(route('switches.login-users.store', $switch), [
                'name' => 'saha',
                'class' => 'yonetici',
            ])
            ->assertSessionHasErrors('class');

        $this->assertNull($device->connection);
    }

    public function test_ad_duzenlemede_degistirilemez(): void
    {
        $switch = $this->switch();
        $account = SwitchLoginUser::factory()->for($switch, 'switch')->create(['name' => 'saha']);

        $this->actingAs($this->operator())
            ->put(route('switches.login-users.update', [$switch, $account]), [
                '_duzenleme' => '1',
                'name' => 'baska',
                'class' => JunosUserClass::ReadOnly->value,
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_baska_switchin_hesabi_eslesmez(): void
    {
        $switch = $this->switch();
        $other = NetworkSwitch::factory()->create();
        $account = SwitchLoginUser::factory()->for($other, 'switch')->create();

        $this->actingAs($this->operator())
            ->delete(route('switches.login-users.destroy', [$switch, $account]))
            ->assertNotFound();
    }

    public function test_cihaza_yazamayan_hesap_ekleyemez(): void
    {
        $switch = $this->switch();
        $device = $this->device();

        $user = User::factory()->create();
        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view']);
        $this->grantDeviceView($user);

        $this->actingAs($user->fresh())
            ->post(route('switches.login-users.store', $switch), [
                'name' => 'saha',
                'class' => JunosUserClass::ReadOnly->value,
            ])
            ->assertForbidden();

        $this->assertNull($device->connection);
    }

    private function device(): FakeJunosDevice
    {
        $device = new FakeJunosDevice;

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
