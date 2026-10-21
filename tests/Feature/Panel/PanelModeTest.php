<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Enums\CredentialProtocol;
use App\Enums\PanelMode;
use App\Enums\PortMode;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Netconf\Transport\NetconfTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Panelin ayrıntı düzeyi.
 *
 * Buradaki asıl soru görünürlük değil GÜVENLİKTİR: bu ekranlarda birçok
 * alanda "gönderilmedi" cihazdaki satırın SİLİNMESİ demektir. Basit modda
 * gizlenen bir alan isteğe hiç girmez; ayarları kuran taraf yalnızca GELEN
 * alanları yazmazsa, kullanıcının GÖRMEDİĞİ bir ayar her kayıtta cihazdan
 * silinirdi.
 */
final class PanelModeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Blade doğrudan işlendiğinde oturumdan gelen hata torbası olmaz;
     *
     * @error yönergesi onu bekler.
     */
    protected function setUp(): void
    {
        parent::setUp();

        View::share('errors', new ViewErrorBag);
    }

    public function test_varsayilan_mod_basittir(): void
    {
        $this->assertSame(PanelMode::Simple, User::factory()->create()->panel_mode);
    }

    public function test_kullanici_kendi_modunu_degistirir(): void
    {
        $user = $this->gelismisYetkili(User::factory()->create(['panel_mode' => PanelMode::Simple]));

        $this->actingAs($user)
            ->post(route('panel-mode.toggle'))
            ->assertRedirect();

        $this->assertSame(PanelMode::Advanced, $user->fresh()->panel_mode);

        $this->actingAs($user->fresh())
            ->post(route('panel-mode.toggle'))
            ->assertRedirect();

        $this->assertSame(PanelMode::Simple, $user->fresh()->panel_mode);
    }

    public function test_oturumsuz_mod_degistirilemez(): void
    {
        $this->post(route('panel-mode.toggle'))->assertRedirect(route('login'));
    }

    /**
     * Mod düğmesi hesap menüsünün İÇİNDE durur.
     *
     * Üst çubukta ayrı bir düğme olarak dururken dar ekranda bölüm menüsünü
     * sıkıştırıyordu. Yetki değil kişisel görünüm tercihi olduğu için yeri
     * hesap işlerinin yanı.
     */
    public function test_mod_dugmesi_hesap_menusunun_icinde(): void
    {
        $govde = $this->actingAs($this->gelismisYetkili(User::factory()->create()))
            ->get(route('hesap.profil'))
            ->assertOk()
            ->getContent();

        $menu = substr($govde, (int) strpos($govde, 'data-menu-liste'));

        $this->assertStringContainsString(route('panel-mode.toggle'), $menu);
        $this->assertSame(1, substr_count($govde, route('panel-mode.toggle')));
    }

    /**
     * BASİT MODDA GİZLENEN ALAN CİHAZDAN SİLİNMEZ.
     *
     * Gizlenen alan formda çizilmediği için isteğe hiç girmez. Ayarlar
     * koşulsuz kurulsaydı, hız boş gelip port otomatik anlaşmaya döner,
     * MTU satırı kalkar ve duplex silinirdi — kullanıcı o alanları hiç
     * görmemişken.
     */
    public function test_basit_modda_gizli_alanlar_cihaza_yazilmaz(): void
    {
        [$switch, $port, $device] = $this->hazirla();

        $this->actingAs($this->operator(PanelMode::Simple))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'enabled' => '1',
                'port_mode' => 'access',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = implode("\n", $device->written);

        $this->assertStringNotContainsString('<speed', $yazilan);
        $this->assertStringNotContainsString('<mtu', $yazilan);
        $this->assertStringNotContainsString('<link-mode', $yazilan);
        $this->assertStringNotContainsString('<description', $yazilan);
    }

    /**
     * GELİŞMİŞ MODDA BOŞ GÖNDERİLEN ALAN SİLİNİR.
     *
     * Fark tam olarak budur: orada alan görünür, kullanıcı onu bilerek
     * boşaltmıştır ve silme bilinçlidir.
     */
    public function test_gelismis_modda_bos_alan_cihazdan_silinir(): void
    {
        [$switch, $port, $device] = $this->hazirla();

        $this->actingAs($this->operator(PanelMode::Advanced))
            ->put(route('switches.ports.update', [$switch, $port]), [
                'enabled' => '1',
                'port_mode' => 'access',
                'description' => '',
                'speed_config' => '',
                'duplex_config' => '',
                'mtu' => '',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $yazilan = implode("\n", $device->written);

        $this->assertStringContainsString('<description delete="delete"/>', $yazilan);
        $this->assertStringContainsString('<mtu delete="delete"/>', $yazilan);
    }

    /**
     * Gelişmiş alan BASİT modda DOM'a hiç girmez.
     *
     * Gizlemek CSS ile yapılsaydı alan yine gönderilir ve "gönderilmedi =
     * dokunma" kuralı bozulurdu; alan gerçekten çizilmemeli. Bileşen
     * doğrudan işlenir: kural tek yerde, orada sınanır.
     */
    public function test_gelismis_alan_basit_modda_cizilmez(): void
    {
        $this->actingAs(User::factory()->create(['panel_mode' => PanelMode::Simple]));

        $cikti = $this->alaniIsle();

        $this->assertStringNotContainsString('name="mtu"', $cikti);
        $this->assertStringNotContainsString('MTU', $cikti);
    }

    public function test_gelismis_alan_gelismis_modda_cizilir(): void
    {
        $this->actingAs($this->gelismisYetkili(User::factory()->create(['panel_mode' => PanelMode::Advanced])));

        $cikti = $this->alaniIsle();

        $this->assertStringContainsString('name="mtu"', $cikti);
        $this->assertStringContainsString('MTU', $cikti);
    }

    /**
     * İşaretsiz alan HER İKİ modda da çizilir: basit mod bir şeyi
     * gizlemek için değil, seyrek kullanılanı kaldırmak içindir.
     */
    public function test_isaretsiz_alan_her_iki_modda_da_cizilir(): void
    {
        foreach (PanelMode::cases() as $mode) {
            $this->actingAs($this->gelismisYetkili(User::factory()->create(['panel_mode' => $mode])));

            $cikti = Blade::render(
                '<x-form.field name="description" label="Açıklama">'
                .'<input name="description" /></x-form.field>',
            );

            $this->assertStringContainsString('name="description"', $cikti);
        }
    }

    /**
     * VARSAYILAN DIŞI DEĞER BASİT MODDA DA GÖRÜNÜR.
     *
     * İşler çoğunlukla varsayılanlarla yürür ve o alanın adı bile geçmez.
     * Ama biri portu zorla 100 Mbps'e çekmiş ya da MTU'yu büyütmüşse, basit
     * moddaki kullanıcı bunu göremezse ekran cihaz hakkında YALAN söylemiş
     * olur. Alan görünür ama GİRDİ DEĞİLDİR: isteğe girmez, dolayısıyla
     * "gönderilmedi = dokunma" kuralı bozulmaz.
     */
    public function test_cihazda_tanimli_gelismis_alan_basit_modda_okunur_gorunur(): void
    {
        $this->actingAs(User::factory()->create(['panel_mode' => PanelMode::Simple]));

        $cikti = $this->alaniIsle('9216 bayt');

        $this->assertStringContainsString('MTU', $cikti);
        $this->assertStringContainsString('9216 bayt', $cikti);
        $this->assertStringNotContainsString('name="mtu"', $cikti);
        $this->assertStringNotContainsString('<input', $cikti);
    }

    /** Varsayılandaki alan basit modda hiç anılmaz: sadeliğin bedeli yok. */
    public function test_varsayilandaki_gelismis_alan_basit_modda_anilmaz(): void
    {
        $this->actingAs(User::factory()->create(['panel_mode' => PanelMode::Simple]));

        $this->assertStringNotContainsString('MTU', $this->alaniIsle(null));
    }

    /** Gelişmiş modda değer varken de DÜZENLENİR; salt okunur satır çıkmaz. */
    public function test_gelismis_modda_tanimli_alan_duzenlenebilir(): void
    {
        $this->actingAs($this->gelismisYetkili(User::factory()->create(['panel_mode' => PanelMode::Advanced])));

        $cikti = $this->alaniIsle('9216 bayt');

        $this->assertStringContainsString('name="mtu"', $cikti);
        $this->assertStringNotContainsString('gelişmiş moda geçin', $cikti);
    }

    /**
     * Port ekranı bu değeri gerçekten besliyor mu?
     *
     * Bileşen doğru davransa bile ekran değeri geçirmezse kullanıcı yine
     * göremez; bağlantı ayrıca sınanır.
     */
    public function test_port_ekrani_zorlanmis_hizi_basit_modda_gosterir(): void
    {
        [$switch, $port] = $this->hazirla();
        $port->update(['speed_config' => '100', 'mtu' => 9216]);

        $cikti = $this->actingAs($this->operator(PanelMode::Simple))
            ->get(route('switches.ports', $switch))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('100 Mbps', $cikti);
        $this->assertStringContainsString('9216 bayt', $cikti);
        $this->assertStringNotContainsString('name="speed_config"', $cikti);
        $this->assertStringNotContainsString('name="mtu"', $cikti);
    }

    private function alaniIsle(?string $current = null): string
    {
        return Blade::render(
            '<x-form.field advanced name="mtu" label="MTU" :current="$current">'
            .'<input name="mtu" /></x-form.field>',
            ['current' => $current],
        );
    }

    /**
     * @return array{0: NetworkSwitch, 1: SwitchPort, 2: FakeJunosDevice}
     */
    private function hazirla(): array
    {
        $switch = NetworkSwitch::factory()->create(['management_ip' => '198.18.20.195']);

        $switch->credentials()->create([
            'protocol' => CredentialProtocol::Netconf,
            'username' => 'admin',
            'password' => 'gizli',
            'port' => 830,
        ]);

        $port = SwitchPort::factory()->for($switch, 'switch')
            ->create(['name' => 'ge-0/0/5', 'port_mode' => PortMode::Unconfigured]);

        $device = new FakeJunosDevice;
        $this->app->instance(NetconfTransport::class, $device);

        return [$switch->refresh(), $port, $device];
    }

    private function operator(PanelMode $mode): User
    {
        $user = $this->gelismisYetkili(User::factory()->create(['panel_mode' => $mode]));

        app(UserPermissionResolver::class)->syncResource($user, 'switches', ['view', 'configure']);
        app(UserPermissionResolver::class)->syncResource($user, 'switch_ports', ['view', 'update']);
        $this->grantDeviceWrite($user);

        return $user->fresh();
    }

    /** "Gelişmiş mod" yetkisi (gelişmiş mod yetkiye bağlı). */
    private function gelismisYetkili(User $user): User
    {
        app(UserPermissionResolver::class)->syncResource($user, 'gorunum_modu', ['view']);

        return $user->fresh();
    }

    /** Gelişmiş moda yalnızca yetkisi olan geçer; yetkisi olmayan basit modda kalır. */
    public function test_yetkisiz_kullanici_gelismis_moda_gecemez(): void
    {
        $user = User::factory()->create(['panel_mode' => PanelMode::Simple]);

        $this->actingAs($user)->post(route('panel-mode.toggle'))->assertForbidden();
        $this->assertSame(PanelMode::Simple, $user->fresh()->panel_mode);

        /* Düğme de görünmez. */
        $this->actingAs($user)->get(route('overview'))->assertDontSee('moda geç', false);

        /* Kaydı "gelişmiş" kalmış ama yetkisi alınmış kişi basit sayılır. */
        $eski = User::factory()->create(['panel_mode' => PanelMode::Advanced]);
        $this->assertSame(PanelMode::Simple, $eski->etkinPanelModu());
    }

    public function test_yonetici_her_zaman_gelismis_moda_gecebilir(): void
    {
        $yonetici = User::factory()->admin()->create(['panel_mode' => PanelMode::Simple]);

        $this->assertTrue($yonetici->gelismisModaGecebilir());
        $this->actingAs($yonetici)->post(route('panel-mode.toggle'))->assertRedirect();
        $this->assertSame(PanelMode::Advanced, $yonetici->fresh()->etkinPanelModu());
    }
}
